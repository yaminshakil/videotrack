<?php

namespace Tests\Feature;

use App\Livewire\Admin\Topics as AdminTopics;
use App\Models\Admin;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Rate;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin topics page as a Livewire component.
 *
 * The plain POST/PUT/DELETE routes still exist, so these cover the paths the
 * browser actually takes now: every save happens in place, with no redirect.
 */
class AdminTopicsComponentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): static
    {
        return $this->actingAs(Admin::first(), 'admin');
    }

    private function channel(string $name = 'windows'): Channel
    {
        return Channel::create(['name' => $name, 'slug' => $name, 'sort_order' => Channel::count()]);
    }

    private function employee(string $username = 'alice'): Employee
    {
        return Employee::create([
            'name' => ucfirst($username), 'username' => $username,
            'password' => 'pw', 'is_active' => true,
        ]);
    }

    public function test_admin_can_add_a_topic_and_assign_it_in_one_go(): void
    {
        $c = $this->channel();
        $e = $this->employee();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->set('channel_id', (string) $c->id)
            ->set('title', 'Install Ollama')
            ->set('category', 'Local AI')
            ->set('link', 'https://ollama.com')
            ->set('employee_id', (string) $e->id)
            ->call('store')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSet('title', '');

        $topic = Topic::firstOrFail();

        $this->assertSame('Install Ollama', $topic->title);
        $this->assertSame($e->id, $topic->assigned_to);
        $this->assertSame(Admin::first()->name, $topic->added_by_label);
        $this->assertSame('admin:'.Admin::first()->id, $topic->added_by_key);
        $this->assertNull($topic->added_by, 'A staff topic must stay off the employee Custom Topics list.');
    }

    /**
     * The key is what the Recently added strip authorises on, so it has to be
     * recorded for a topic added through the form, not just for a seeded one.
     */
    public function test_a_topic_added_through_the_form_is_editable_in_the_strip(): void
    {
        $c = $this->channel();

        $this->admin();

        Livewire::test(AdminTopics::class, ['panel' => 'add'])
            ->set('channel_id', (string) $c->id)
            ->set('title', 'Just added')
            ->call('store')
            ->assertSeeHtml('data-recent-edit="'.Topic::firstOrFail()->id.'"')
            ->assertSeeHtml('data-recent-delete="'.Topic::firstOrFail()->id.'"');
    }

    public function test_adding_a_topic_without_a_channel_or_title_creates_nothing(): void
    {
        $this->channel();
        $this->employee();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('store')
            ->assertHasErrors(['channel_id', 'title']);

        $this->assertSame(0, Topic::count());
    }

    public function test_adding_a_topic_rejects_an_unknown_employee(): void
    {
        $c = $this->channel();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->set('channel_id', (string) $c->id)
            ->set('title', 'Ghost')
            ->set('employee_id', '999')
            ->call('store')
            ->assertHasErrors('employee_id');

        $this->assertSame(0, Topic::count());
    }

    public function test_editing_a_row_loads_it_and_saves_the_change(): void
    {
        $topic = $this->topic();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('edit', $topic->id)
            ->assertSet('editing', $topic->id)
            ->assertSet('draft.title', 'Original')
            ->set('draft.title', 'Renamed')
            ->set('draft.category', 'Linux / GPU')
            ->set('draft.link', 'https://example.com')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editing', null);

        $topic->refresh();

        $this->assertSame('Renamed', $topic->title);
        $this->assertSame('Linux / GPU', $topic->category);
        $this->assertSame('https://example.com', $topic->link);
    }

    public function test_saving_a_row_without_a_title_is_refused(): void
    {
        $topic = $this->topic();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('edit', $topic->id)
            ->set('draft.title', '')
            ->call('saveEdit')
            ->assertHasErrors('draft.title')
            ->assertSet('editing', $topic->id);

        $this->assertSame('Original', $topic->fresh()->title);
    }

    public function test_cancelling_an_edit_leaves_the_topic_alone(): void
    {
        $topic = $this->topic();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('edit', $topic->id)
            ->set('draft.title', 'Discarded')
            ->call('cancelEdit')
            ->assertSet('editing', null)
            ->assertSet('draft', []);

        $this->assertSame('Original', $topic->fresh()->title);
    }

    public function test_toggling_a_row_completes_it_and_records_what_it_paid(): void
    {
        $c = $this->channel();
        $e = $this->employee();
        Rate::create(['channel_id' => $c->id, 'employee_id' => $e->id, 'amount' => 75]);
        $topic = $this->topic($c, $e);

        $this->admin();

        Livewire::test(AdminTopics::class)->call('toggle', $topic->id);

        $topic->refresh();
        $this->assertTrue($topic->is_done);
        $this->assertSame(75.0, (float) $topic->earned_amount);
        $this->assertSame($e->id, $topic->completed_by);

        // and back again, which drops the earning with it
        Livewire::test(AdminTopics::class)->call('toggle', $topic->id);

        $topic->refresh();
        $this->assertFalse($topic->is_done);
        $this->assertSame(0.0, (float) $topic->earned_amount);
    }

    public function test_deleting_a_row_also_closes_it_if_it_was_open(): void
    {
        $topic = $this->topic();

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('edit', $topic->id)
            ->call('delete', $topic->id)
            ->assertHasNoErrors()
            ->assertSet('editing', null);

        $this->assertSame(0, Topic::count());
    }

    public function test_the_page_still_opens_at_its_old_url(): void
    {
        $this->topic();

        $this->admin()->get('/admin/topics')->assertOk()->assertSee('Topics');
    }

    /**
     * The Recently added strip is the quickest way to fix a topic you have just
     * added, so each row there edits and deletes it without hunting for the row in
     * the list below.
     */
    public function test_a_recently_added_topic_can_be_edited_from_the_strip(): void
    {
        $topic = $this->topic();

        $this->admin();
        $this->claim($topic);

        $lw = Livewire::test(AdminTopics::class, ['panel' => 'add']);

        // the strip offers both controls, on the admin who added the topic
        $lw->assertSeeHtml('data-recent-edit="'.$topic->id.'"')
            ->assertSeeHtml('data-recent-delete="'.$topic->id.'"')
            ->assertSeeHtml('wire:click="editRecent('.$topic->id.')"')
            ->assertSeeHtml('wire:click="deleteRecent('.$topic->id.')"');

        // pressing Edit opens the editor in the strip itself, seeded with what is stored
        $lw->call('editRecent', $topic->id)
            ->assertSet('editingRecent', $topic->id)
            ->assertSet('recentDraft.title', 'Original')
            ->assertSeeHtml('wire:submit="saveRecentEdit"');

        // ... and the table's own editor is untouched by it
        $this->assertNull($lw->get('editing'));

        $lw->set('recentDraft.title', 'Fixed from the strip')
            ->call('saveRecentEdit')
            ->assertHasNoErrors()
            ->assertSet('editingRecent', null);

        $this->assertSame('Fixed from the strip', $topic->fresh()->title);
    }

    public function test_a_recently_added_topic_can_be_deleted_from_the_strip(): void
    {
        $topic = $this->topic();

        $this->admin();
        $this->claim($topic);

        Livewire::test(AdminTopics::class)
            ->call('deleteRecent', $topic->id)
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertNull(Topic::find($topic->id));
    }

    /**
     * The strip belongs to whoever added the topic. Another admin still sees the row
     * — it is recent, after all — but gets no controls on it, and calling the
     * component directly changes nothing, because /livewire/update carries no proof
     * that the caller was ever shown a button.
     */
    public function test_the_strip_offers_no_controls_on_a_topic_another_admin_added(): void
    {
        $other = Admin::create(['name' => 'Rosa', 'username' => 'rosa', 'password' => 'pw']);

        $topic = $this->topic();
        $topic->forceFill([
            'added_by_label' => $other->name,
            'added_by_key' => 'admin:'.$other->id,
        ])->save();

        $this->admin();

        $lw = Livewire::test(AdminTopics::class, ['panel' => 'add'])
            ->assertSee($topic->title, 'The row is still listed as recent.')
            ->assertDontSeeHtml('data-recent-edit="'.$topic->id.'"')
            ->assertDontSeeHtml('data-recent-delete="'.$topic->id.'"');

        $lw->call('editRecent', $topic->id)->assertForbidden();

        // Each refusal is its own request, as it would be from the browser.
        Livewire::test(AdminTopics::class, ['panel' => 'add'])->call('deleteRecent', $topic->id)->assertForbidden();

        $this->assertSame('Original', $topic->fresh()->title);
    }

    /**
     * Names are not identities: two admins with the same name are still two people,
     * so the strip must not fall back to matching on the label when the topic
     * records a key. Otherwise either of them could edit the other's topics.
     */
    public function test_a_shared_name_does_not_grant_access_to_someone_elses_topic(): void
    {
        $other = Admin::create([
            'name' => Admin::first()->name, 'username' => 'rosa', 'password' => 'pw',
        ]);

        $topic = $this->topic();
        $topic->forceFill(['added_by_label' => $other->name, 'added_by_key' => 'admin:'.$other->id])->save();

        $this->admin();

        Livewire::test(AdminTopics::class, ['panel' => 'add'])
            ->assertDontSeeHtml('data-recent-edit="'.$topic->id.'"')
            ->call('deleteRecent', $topic->id)
            ->assertForbidden();

        $this->assertSame(1, Topic::count());
    }

    /**
     * Topics that predate added_by_key only ever recorded a name, and a name is the
     * best that exists for them, so their owner can still correct them. Nothing can
     * be attributed with confidence, so a row with no label at all stays closed.
     */
    public function test_a_topic_with_only_a_name_is_manageable_by_that_name(): void
    {
        $topic = $this->topic();
        $topic->forceFill(['added_by_label' => 'Seed', 'added_by_key' => null])->save();

        $this->admin();

        Livewire::test(AdminTopics::class, ['panel' => 'add'])
            ->assertDontSeeHtml('data-recent-edit="'.$topic->id.'"');

        $topic->forceFill(['added_by_label' => Admin::first()->name])->save();

        Livewire::test(AdminTopics::class, ['panel' => 'add'])
            ->assertSeeHtml('data-recent-edit="'.$topic->id.'"')
            ->call('editRecent', $topic->id)
            ->assertSet('editingRecent', $topic->id);
    }

    /**
     * A crafted call to save with no row open has no id to save, and must be refused
     * rather than reaching the update with a null one.
     */
    public function test_saving_the_strip_with_no_row_open_is_refused(): void
    {
        $topic = $this->topic();

        $this->admin();
        $this->claim($topic);

        Livewire::test(AdminTopics::class)
            ->set('recentDraft.title', 'Nothing is open')
            ->call('saveRecentEdit')
            ->assertNotFound();

        $this->assertSame('Original', $topic->fresh()->title);
    }

    public function test_the_strip_only_offers_editing_for_topics_it_lists(): void
    {
        $channel = $this->channel();

        $recent = $this->topic($channel);
        $old = $this->topic($channel);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->admin();
        $this->claim($recent);
        $this->claim($old);

        // The old topic is outside the 3-day strip, so the strip offers no control
        // for it even though the list on the Show Topic page still lets the admin
        // edit it.
        Livewire::test(AdminTopics::class, ['panel' => 'add'])
            ->assertSeeHtml('data-recent-edit="'.$recent->id.'"')
            ->assertDontSeeHtml('data-recent-edit="'.$old->id.'"');
    }

    /**
     * /livewire/update carries none of the page's route middleware, so the
     * component has to refuse a caller who is not an admin by itself.
     */
    public function test_a_signed_out_browser_is_sent_to_the_login_page(): void
    {
        Livewire::test(AdminTopics::class)
            ->assertRedirect(route('login'));
    }

    public function test_a_manager_cannot_open_the_admin_page(): void
    {
        $m = Manager::create(['name' => 'Musa', 'username' => 'musa', 'password' => 'pw', 'is_active' => true]);

        $this->actingAs($m, 'manager');

        Livewire::test(AdminTopics::class)
            ->assertRedirect(route('login'));
    }

    /**
     * Mounting as the admin and then losing the session must not leave a
     * component that still accepts calls: the follow-up request goes to
     * /livewire/update, which never passes through the admin route middleware.
     */
    public function test_losing_the_session_mid_session_stops_the_component(): void
    {
        $topic = $this->topic();

        $this->admin();

        $component = Livewire::test(AdminTopics::class);

        auth()->guard('admin')->logout();

        $component->call('delete', $topic->id)->assertForbidden();

        $this->assertSame(1, Topic::count(), 'The call must not have reached the delete.');
    }

    /**
     * The list is a real table, not a stack of divs: a head row, one tbody per
     * channel, and a matching number of cells in every row. This pins the shape so
     * a column cannot be added to the header and forgotten in the body.
     */
    public function test_topics_render_as_a_table_grouped_by_channel(): void
    {
        $windows = $this->channel('windows');
        $linux = $this->channel('linux');

        $this->topic($windows, null, 'Win one');
        $this->topic($windows, null, 'Win two');
        $this->topic($linux, null, 'Linux one');

        $this->admin();

        $html = Livewire::test(AdminTopics::class)->html();

        $this->assertStringContainsString('<table class="tstable">', $html);
        $this->assertSame(2, substr_count($html, '<table class="tstable">'), 'one table per channel');
        $this->assertSame(2, substr_count($html, 'data-topic-group'));

        // Both channel headings, each with how many topics it holds.
        $this->assertStringContainsString('2 topics', $html);
        $this->assertStringContainsString('1 topic<', $html);

        // Every topic row carries the data the instant search and the
        // scroll-to-row helper rely on.
        foreach (['Win one', 'Win two', 'Linux one'] as $title) {
            $this->assertStringContainsString('data-topic-row', $html);
            $this->assertStringContainsString(e($title), $html);
        }

        $this->assertSame(3, substr_count($html, 'data-topic-row='));

        // A hand-written table breaks silently if a row is short a cell, so check
        // the shape of the real DOM rather than trusting the column count to match.
        // Only the tables are parsed (one per channel, extracted non-greedily): the
        // rest of the page is full of HTML5 elements that the libxml parser behind
        // DOMDocument refuses to load.
        $this->assertSame(2, preg_match_all('/<table class="tstable">.*?<\/table>/s', $html, $m), 'one topic table per channel');

        $dom = new \DOMDocument;
        $dom->loadHTML('<div>'.implode('', $m[0]).'</div>');
        $xpath = new \DOMXPath($dom);

        $this->assertSame(12, $xpath->query('//table/thead/tr/th')->length, '6 columns in each of the 2 tables');

        $rows = $xpath->query('//tr[contains(concat(" ", @class, " "), " trow ")]');
        $this->assertSame(3, $rows->length);

        foreach ($rows as $row) {
            $this->assertSame(6, $row->getElementsByTagName('td')->length, 'Every topic row needs one cell per column');
        }
    }

    /**
     * The Show Topic page's whole point is to see what just landed, so each channel's
     * table lists its own topics newest first — not by category or by the manual
     * sort_order that decides the tracker checklist's order — while the channels
     * themselves keep appearing in their own sort_order, regardless of which one
     * happens to hold the single newest topic.
     */
    public function test_each_channels_topics_are_listed_newest_first(): void
    {
        $windows = $this->channel('windows');
        $linux = $this->channel('linux');

        // Manual sort_order says "first" would be oldest — newest-first must ignore it.
        $oldest = $this->topic($windows, null, 'Oldest Windows topic');
        $oldest->forceFill(['sort_order' => 1, 'created_at' => now()->subDays(3)])->save();

        $newest = $this->topic($windows, null, 'Newest Windows topic');
        $newest->forceFill(['sort_order' => 99, 'created_at' => now()])->save();

        $middle = $this->topic($windows, null, 'Middle Windows topic');
        $middle->forceFill(['sort_order' => 50, 'created_at' => now()->subDay()])->save();

        // Linux's only topic is newer than everything in Windows, but Windows still
        // has the lower sort_order, so its box must still come first.
        $linuxTopic = $this->topic($linux, null, 'Linux topic');
        $linuxTopic->forceFill(['created_at' => now()->addHour()])->save();

        $this->admin();

        $groups = Livewire::test(AdminTopics::class)->viewData('groups');

        $this->assertSame([$windows->id, $linux->id], collect($groups)->pluck('channel.id')->all());

        $this->assertSame(
            ['Newest Windows topic', 'Middle Windows topic', 'Oldest Windows topic'],
            $groups[0]['topics']->pluck('title')->all()
        );
    }

    public function test_opening_a_row_renders_the_editor_below_it_in_the_table(): void
    {
        $t = $this->topic(null, null, 'Editable topic');

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('edit', $t->id)
            ->assertSet('editing', $t->id)
            ->assertSeeHtml('trow-edit')
            // a full-width panel, so the editor lines up with the table it belongs to
            ->assertSeeHtml('colspan="6"')
            ->assertSeeHtml('wire:submit="saveEdit"');
    }

    /**
     * Every action re-renders the whole table and ships it to the browser, so the
     * table only shows the newest PREVIEW_LIMIT topics of each channel until it is
     * asked for the rest. Without this a one-line edit re-sent several hundred rows.
     *
     * The cut is per channel rather than one pool for the whole table. That is what
     * makes the sections honest: a single shared pool of PREVIEW_LIMIT is spent on
     * whichever channels happened to get the newest topics, so a channel of 193 could
     * end up showing 5 rows and a heading reading "5 topics" — which is how most of
     * its completed topics went missing without the page looking wrong.
     */
    public function test_the_table_shows_only_the_newest_hundred_of_each_channel_until_asked_for_more(): void
    {
        $windows = $this->channel('windows');
        $linux = $this->channel('linux');

        // 120 topics, oldest first, spread over both channels.
        $this->bulkTopics(120, [$windows->id, $linux->id]);
        $oldest = Topic::orderBy('id')->firstOrFail();

        $this->admin();

        $lw = Livewire::test(AdminTopics::class);
        $html = $lw->html();

        // Neither channel has more than PREVIEW_LIMIT of its own, so the cut does not
        // bite here and every row is listed.
        $this->assertSame(120, substr_count($html, 'data-topic-row='));
        $this->assertStringContainsString('data-topic-row="'.$oldest->id.'"', $html);
        $this->assertStringNotContainsString('data-topic-cut', $html, 'Nothing is held back, so no footer.');
    }

    /**
     * A channel with more than PREVIEW_LIMIT topics of its own is the case the cut
     * exists for, and it has to be cut per channel: Windows here holds far more than
     * Linux, and a shared pool would hand the whole budget to one of them and take
     * rows the other one still needs.
     */
    public function test_the_preview_cut_is_per_channel_not_one_shared_pool(): void
    {
        $windows = $this->channel('windows');
        $linux = $this->channel('linux');

        // 150 Windows topics, then 3 Linux ones, so Windows is both older and larger.
        $this->bulkTopics(150, [$windows->id]);
        $this->bulkTopics(3, [$linux->id]);

        $linuxOldest = Topic::where('channel_id', $linux->id)->orderBy('id')->firstOrFail();
        $windowsOldest = Topic::where('channel_id', $windows->id)->orderBy('id')->firstOrFail();

        $this->admin();

        $lw = Livewire::test(AdminTopics::class);
        $html = $lw->html();

        $this->assertSame(103, substr_count($html, 'data-topic-row='), '100 of Windows plus all 3 of Linux.');
        $this->assertSame(2, substr_count($html, 'data-topic-group'), 'Both channels survive the cut.');

        // Linux is inside its own budget, so all of it is listed even though the
        // table as a whole is over the limit.
        $this->assertStringContainsString('data-topic-row="'.$linuxOldest->id.'"', $html);

        // Windows is the one that is cut, and its oldest topic is the one held back.
        $this->assertStringNotContainsString('data-topic-row="'.$windowsOldest->id.'"', $html);

        // The section heading has to admit what is missing. It used to read a bare
        // count of what was on screen, so a cut channel looked like a small channel.
        $this->assertStringContainsString('newest 100 of 150', $html);

        // ... and the footer says what it is holding back in total, with a way to see it.
        $lw->assertSeeHtml('data-topic-cut')
            ->assertSee('Showing the newest '.Topic::PREVIEW_LIMIT.' of each channel, 103 of 153 topics in all')
            ->assertSeeHtml('wire:click="showAllTopics"');

        $lw->call('showAllTopics')->assertSet('showAll', true);
        $this->assertSame(153, substr_count($lw->html(), 'data-topic-row='));
        $this->assertStringContainsString('data-topic-row="'.$windowsOldest->id.'"', $lw->html());

        // and the way back, so the page is not stuck on the slow list for good
        $lw->call('showNewestOnly')->assertSet('showAll', false);
        $this->assertSame(103, substr_count($lw->html(), 'data-topic-row='));
    }

    /** A list under the cut has nothing held back, so it gets no footer. */
    public function test_a_short_list_has_no_show_all_footer(): void
    {
        $c = $this->channel();

        $this->topic($c, null, 'One of three');
        $this->topic($c, null, 'Two of three');
        $this->topic($c, null, 'Three of three');

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->assertDontSeeHtml('data-topic-cut')
            ->assertDontSeeHtml('wire:click="showAllTopics"');
    }

    /**
     * The strip is a shortcut into the list, not a separate island: both are cut on
     * recency, so everything the strip offers is a row the Show Topic page's table is
     * already showing. That is what makes "only the newest hundred" safe rather than
     * a trap.
     */
    public function test_everything_the_strip_offers_is_already_in_the_cut_list(): void
    {
        $c = $this->channel();
        $this->bulkTopics(Topic::PREVIEW_LIMIT + 20, [$c->id]);

        foreach (range(1, 4) as $i) {
            $this->claim($this->topic($c, null, "Just added $i"));
        }

        $this->admin();

        $stripHtml = Livewire::test(AdminTopics::class, ['panel' => 'add'])->html();

        preg_match_all('/data-recent-edit="(\d+)"/', $stripHtml, $m);

        $this->assertNotEmpty($m[1], 'The strip has something to offer.');

        $listHtml = Livewire::test(AdminTopics::class, ['panel' => 'show'])->html();

        foreach ($m[1] as $id) {
            $this->assertStringContainsString(
                'data-topic-row="'.$id.'"',
                $listHtml,
                'A topic the strip can edit must also be a row in the Show Topic page\'s list.'
            );
        }
    }

    /**
     * Marking a row done re-renders the list too, so the checkbox on an older topic
     * has to keep working once the list is showing everything.
     */
    public function test_the_done_checkbox_still_works_on_the_long_list(): void
    {
        $c = $this->channel();
        $this->bulkTopics(Topic::PREVIEW_LIMIT + 3, [$c->id]);
        $topic = $this->topic($c, null, 'Tick me');

        $this->admin();

        Livewire::test(AdminTopics::class)
            ->call('showAllTopics')
            ->assertSeeHtml('data-topic-row="'.$topic->id.'"')
            ->call('toggle', $topic->id);

        $this->assertTrue($topic->fresh()->is_done);
    }

    /**
     * The Show Topic page — the default, and what the sidebar's "Show Topic" link
     * opens — renders the channel chooser, the instant search and the list directly,
     * with no dropdown to open first and none of the add form's markup on it.
     */
    public function test_the_show_topic_page_renders_the_list_directly(): void
    {
        $c = $this->channel();
        $this->claim($this->topic($c, null, 'A listed topic'));

        $this->admin();

        $html = $this->get('/admin/topics')->assertOk()->getContent();

        $this->assertStringContainsString('All channels', $html);
        $this->assertStringContainsString('data-topic-search', $html);
        $this->assertStringContainsString('data-topic-row=', $html);

        // No leftover toggle, and no trace of the add form on this page.
        $this->assertStringNotContainsString('data-topic-menu-trigger', $html);
        $this->assertStringNotContainsString('wire:submit="store"', $html);
    }

    /**
     * The Add Topic page — reached from the sidebar's "Add Topic" link, ?panel=add —
     * renders the form and what was just added directly, with none of the list's
     * markup on it.
     */
    public function test_the_add_topic_page_renders_the_form_directly(): void
    {
        $c = $this->channel();
        $this->claim($this->topic($c, null, 'A listed topic'));

        $this->admin();

        $html = $this->get('/admin/topics?panel=add')->assertOk()->getContent();

        $this->assertStringContainsString('wire:submit="store"', $html);
        $this->assertStringContainsString('Recently added', $html);
        $this->assertStringContainsString('data-recent-edit=', $html);

        // No trace of the list on this page.
        $this->assertStringNotContainsString('data-topic-search', $html);
        $this->assertStringNotContainsString('data-topic-row=', $html);
    }

    /**
     * The channel chooser in the show dropdown narrows the list, the count behind the
     * "show all" footer and the Recently added strip together, and it lives in the URL
     * so a filtered list survives a reload. An unknown channel narrows to nothing
     * rather than quietly showing everything.
     */
    public function test_the_channel_chooser_narrows_the_list_and_the_strip(): void
    {
        $windows = $this->channel('windows');
        $linux = $this->channel('linux');

        $this->topic($windows, null, 'Windows one');
        $this->claim($this->topic($linux, null, 'Linux one'));

        $this->admin();

        $channelIds = fn (array $groups) => collect($groups)->pluck('channel.id')->all();

        $all = Livewire::test(AdminTopics::class);
        $this->assertSame([$windows->id, $linux->id], $channelIds($all->viewData('groups')));
        $this->assertSame(2, $all->viewData('recent')['total']);

        $one = Livewire::test(AdminTopics::class, ['filter' => (string) $linux->id]);
        $this->assertSame([$linux->id], $channelIds($one->viewData('groups')));
        $this->assertSame(1, $one->viewData('topicTotal'));
        $this->assertSame(1, $one->viewData('recent')['total'], 'The strip follows the chooser.');
        $one->assertDontSee('Windows one');

        // A channel that does not exist matches nothing; it never widens the list.
        $none = Livewire::test(AdminTopics::class, ['filter' => '999']);
        $this->assertSame([], $channelIds($none->viewData('groups')));
        $this->assertSame(0, $none->viewData('topicTotal'));

        // ?channel= is the same state, so a filtered list is a link that can be shared
        // or reloaded rather than a click that is forgotten. withQueryParams sticks to
        // the Livewire manager for the rest of the test, so it goes last and is undone.
        Livewire::withQueryParams(['channel' => $linux->id])
            ->test(AdminTopics::class)
            ->assertSet('filter', (string) $linux->id)
            ->assertSee('Linux one')
            ->assertDontSee('Windows one');

        Livewire::withQueryParams([]);
    }

    /**
     * The status, assignee and added-since filters each narrow the table on their
     * own, in combination, and independently of the channel chooser — and, unlike
     * the instant text search, they run against every matching topic rather than
     * just the newest PREVIEW_LIMIT on screen.
     */
    public function test_the_status_assignee_and_added_filters_narrow_the_list(): void
    {
        $c = $this->channel();
        $alice = $this->employee('alice');
        $bob = $this->employee('bob');

        $done = $this->topic($c, $alice, 'Done by Alice');
        $done->toggleDone();
        $pendingAlice = $this->topic($c, $alice, 'Pending, Alice');
        $pendingBob = $this->topic($c, $bob, 'Pending, Bob');
        $unassigned = $this->topic($c, null, 'Unassigned');
        $unassigned->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->admin();

        // Status alone.
        $doneOnly = Livewire::test(AdminTopics::class, ['statusFilter' => 'done']);
        $this->assertSame(['Done by Alice'], $doneOnly->viewData('topics')->pluck('title')->all());

        $pendingOnly = Livewire::test(AdminTopics::class, ['statusFilter' => 'pending']);
        $this->assertSame(
            ['Pending, Bob', 'Pending, Alice', 'Unassigned'],
            $pendingOnly->viewData('topics')->pluck('title')->all()
        );

        // Assignee alone, including "Unassigned".
        $alicesOnly = Livewire::test(AdminTopics::class, ['assigneeFilter' => (string) $alice->id]);
        $this->assertSame(
            ['Pending, Alice', 'Done by Alice'],
            $alicesOnly->viewData('topics')->pluck('title')->all()
        );

        $unassignedOnly = Livewire::test(AdminTopics::class, ['assigneeFilter' => '0']);
        $this->assertSame(['Unassigned'], $unassignedOnly->viewData('topics')->pluck('title')->all());

        // Added-since alone: the 10-day-old row falls outside "last 7 days".
        $recentOnly = Livewire::test(AdminTopics::class, ['addedFilter' => '7']);
        $this->assertNotContains('Unassigned', $recentOnly->viewData('topics')->pluck('title')->all());
        $this->assertCount(3, $recentOnly->viewData('topics'));

        // Combined: pending and Alice's.
        $combined = Livewire::test(AdminTopics::class, [
            'filter' => (string) $c->id, 'statusFilter' => 'pending', 'assigneeFilter' => (string) $alice->id,
        ]);
        $this->assertSame(['Pending, Alice'], $combined->viewData('topics')->pluck('title')->all());

        // Clearing puts everything back, the channel chooser included, since all
        // four filters now share the one bar and one "Clear filters" button.
        $combined->call('clearFilters');
        $this->assertSame('', $combined->get('filter'));
        $this->assertSame('all', $combined->get('statusFilter'));
        $this->assertSame('', $combined->get('assigneeFilter'));
        $this->assertSame('', $combined->get('addedFilter'));
        $this->assertCount(4, $combined->viewData('topics'));
    }

    /**
     * Every channel in the chooser carries its topic count, and an empty channel says
     * so: picking a channel is the one click on this page that can return nothing, so
     * the count is what tells an empty channel apart from a click that failed.
     */
    public function test_the_channel_chooser_says_what_each_channel_holds(): void
    {
        $windows = $this->channel('windows');
        $linux = $this->channel('linux');
        $empty = $this->channel('macos');

        $this->topic($windows, null, 'One');
        $this->topic($windows, null, 'Two');
        $this->topic($linux, null, 'Three');

        $this->admin();

        $lw = Livewire::test(AdminTopics::class);

        $this->assertSame(
            [$windows->id => 2, $linux->id => 1],
            $lw->viewData('channelCounts')
        );

        $lw->assertSeeHtml('<option value="'.$windows->id.'">'.$windows->icon.' '.$windows->name.' (2)</option>')
            // The empty channel is marked as empty, and "All channels" adds up.
            ->assertSeeHtml('<option value="'.$empty->id.'">'.$empty->icon.' '.$empty->name.' (0)</option>')
            ->assertSeeHtml('<option value="">All channels (3)</option>');

        // And a channel with nothing in it explains itself rather than showing a bare
        // "no topics match yet", which reads as a click that did nothing.
        Livewire::test(AdminTopics::class, ['filter' => (string) $empty->id])
            ->assertSee('No topics in this channel yet.')
            ->assertDontSee('No topics at all yet.');
    }

    /**
     * Six columns of topic data cannot be read on a phone. Rather than sideways scrolling
     * or dropping columns, each row is presented as a card: the header row is hidden and
     * every value names itself, including the two columns the shared mobile rule drops.
     */
    public function test_the_topic_table_becomes_a_card_list_on_a_phone(): void
    {
        $this->topic($this->channel('windows'), null, 'Install Ollama on the build box');

        $this->admin();

        $html = Livewire::test(AdminTopics::class)->html();

        // With the header row out of sight, each value has to say what it is.
        $this->assertStringContainsString('data-label="Category"', $html);
        $this->assertStringContainsString('data-label="Assigned to"', $html);
        $this->assertStringContainsString('data-label="Added by"', $html);

        $css = file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('.tstable thead{display:block;position:absolute', $css);
        $this->assertStringContainsString('.tstable td.hide-sm{display:flex}', $css);
        $this->assertStringContainsString('td[data-label]::before{content:attr(data-label)', $css);

        // The columns get thin before the phone breakpoint, and the one that repeats what
        // the channel heading above it already says is the one that goes.
        $this->assertStringContainsString('.tstable .c-by{display:none}', $css);

        // Each channel's own column header pins to the top of its own scroll box.
        $this->assertStringContainsString('.tstable thead th{position:sticky;top:0', $css);
    }

    private function bulkTopics(int $count, array $channelIds): void
    {
        $oldest = now()->subDays(30)->subMinutes($count);

        Topic::insert(array_map(fn ($i) => [
            'channel_id' => $channelIds[$i % count($channelIds)],
            'title' => "Bulk topic $i", 'category' => 'Other', 'link' => '',
            'sort_order' => $i,
            'created_at' => $oldest->copy()->addMinutes($i), 'updated_at' => $oldest->copy()->addMinutes($i),
        ], range(1, $count)));
    }

    /**
     * Mark a topic as added by the signed-in admin, which is what the strip needs
     * before it will offer any controls on it. $this->admin() must have run first.
     */
    private function claim(Topic $topic): Topic
    {
        return $topic->forceFill([
            'added_by_label' => Admin::first()->name,
            'added_by_key' => 'admin:'.Admin::first()->id,
        ])->save() ? $topic->refresh() : $topic;
    }

    private function topic(?Channel $channel = null, ?Employee $employee = null, ?string $title = null): Topic
    {
        return Topic::create([
            'channel_id' => ($channel ?? $this->channel())->id,
            'title' => $title ?? 'Original',
            'category' => 'Other',
            'link' => '',
            'assigned_to' => $employee?->id,
            'added_by' => null,
            'added_by_label' => 'Seed',
            'sort_order' => 10,
        ]);
    }
}

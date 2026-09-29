<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\Topics as AdminTopics;
use App\Livewire\RateEditor;
use App\Models\Admin;
use App\Models\Bonus;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Topic;
use App\Services\YouTube;
use App\Support\Earnings;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class TrackerTest extends TestCase
{
    use RefreshDatabase;

    private function channel(string $slug = 'windows'): Channel
    {
        return Channel::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'icon' => '🪟', 'badge' => ucfirst($slug), 'sort_order' => 0]
        );
    }

    private function topic(array $attrs = []): Topic
    {
        return Topic::create($attrs + [
            'channel_id' => $this->channel()->id,
            'title' => 'Install Ollama on Windows',
            'category' => 'Windows',
        ]);
    }

    private function employee(string $username = 'emp', string $password = 'pw', array $extra = []): Employee
    {
        return Employee::create(['name' => ucfirst($username), 'username' => $username, 'password' => $password] + $extra);
    }

    private function admin(): static
    {
        // The admin is a real row now, seeded from the environment by the
        // create_admins_table migration.
        return $this->actingAs(Admin::first(), 'admin');
    }

    private function managerChannels(Channel ...$channels): Manager
    {
        $m = Manager::create(['name' => 'M', 'username' => 'mgr', 'password' => 'pw', 'is_active' => true]);
        $m->channels()->sync(collect($channels)->pluck('id'));

        return $m;
    }

    private function loginAs(string $username, string $password = 'pw'): void
    {
        $this->post('/login', ['username' => $username, 'password' => $password]);
    }

    private function fakeYoutube(string $title = 'My Great Video'): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => $title], 200)]);
    }

    // ------------------------------------------------------------------ basics

    public function test_seeder_imports_topics_and_is_rerunnable(): void
    {
        $this->seed();
        $count = Topic::count();
        $this->assertGreaterThan(400, $count);
        $this->assertSame(4, Channel::count());

        Topic::first()->toggleDone();
        $this->seed();

        $this->assertSame($count, Topic::count());
        $this->assertSame(1, Topic::where('is_done', true)->count());
    }

    public function test_category_for(): void
    {
        $this->assertSame('Linux / GPU', Topic::categoryFor('Install Ollama on Ubuntu'));
        $this->assertSame('Windows', Topic::categoryFor('Install Zed on Windows'));
        $this->assertSame('AI Tools', Topic::categoryFor('Get Claude for free'));
        $this->assertSame('Other', Topic::categoryFor('Something else'));
    }

    public function test_landing_page(): void
    {
        $this->topic();

        $this->get('/')->assertOk()
            ->assertSee('Plan every video topic')
            ->assertSee(route('login'), false)
            ->assertSee('1 topics');
    }

    // ----------------------------------------------------------------- tracker

    public function test_index_lists_and_filters_topics(): void
    {
        $this->topic(['title' => 'Alpha topic']);
        $this->topic(['title' => 'Beta topic', 'is_done' => true]);

        $this->get('/tracker')->assertOk()->assertSee('Alpha topic')->assertSee('Beta topic');
        $this->get('/tracker?q=alpha')->assertSee('Alpha topic')->assertDontSee('Beta topic');
        $this->get('/tracker?status=done')->assertSee('Beta topic')->assertDontSee('Alpha topic');
        $this->get('/tracker?status=pending')->assertSee('Alpha topic')->assertDontSee('Beta topic');
        $this->get('/tracker?channel=nope')->assertSee('No topics match');
    }

    /**
     * A bookmarked or hand-edited URL can arrive as ?q[]=… rather than ?q=….
     * The filters are interpolated as strings, and casting an array to a string
     * is fatal in PHP 8, so the tracker used to answer those with a 500 instead
     * of the full list. Anything non-scalar should be read as "no filter".
     */
    public function test_a_filter_sent_as_an_array_falls_back_to_no_filter(): void
    {
        $this->topic(['title' => 'Alpha topic']);
        $this->topic(['title' => 'Beta topic']);

        foreach (['q', 'channel', 'status'] as $key) {
            $this->get("/tracker?{$key}[]=x")
                ->assertOk()
                ->assertSee('Alpha topic')
                ->assertSee('Beta topic');
        }
    }

    public function test_tracker_is_read_only_for_visitors(): void
    {
        $t = $this->topic();

        $this->get('/tracker')->assertSee('Read-only view');
        $this->postJson("/topics/{$t->id}/toggle")->assertForbidden();
        $this->assertFalse($t->fresh()->is_done);
    }

    public function test_employee_sees_only_their_topics_in_the_tracker(): void
    {
        $me = $this->employee();
        $mine = $this->topic(['title' => 'Mine', 'assigned_to' => $me->id]);
        $this->topic(['title' => 'Someone elses']);
        $this->topic(['title' => 'Unassigned']);

        $this->get('/tracker')->assertSee('Mine')->assertSee('Someone elses')->assertSee('Unassigned');

        $this->loginAs('emp');
        $r = $this->get('/tracker')->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Someone elses')
            ->assertDontSee('Unassigned');
        $this->assertSame(1, $r->viewData('stats')['total']);
        $channels = $r->viewData('channels');
        $this->assertSame([(int) $mine->channel_id], $channels->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_admin_toggle_returns_stats_and_records_completion(): void
    {
        $t = $this->topic();
        $this->topic(['title' => 'Other']);

        $this->admin()->postJson("/topics/{$t->id}/toggle")
            ->assertOk()
            ->assertJson(['ok' => true, 'is_done' => true,
                'stats' => ['total' => 2, 'done' => 1, 'remaining' => 1, 'percent' => 50]]);
        $this->assertNotNull($t->fresh()->completed_at);

        $this->admin()->postJson("/topics/{$t->id}/toggle")->assertJson(['is_done' => false]);
        $this->assertNull($t->fresh()->completed_at);
        $this->admin()->postJson('/topics/9999/toggle')->assertNotFound();
    }

    // ------------------------------------------------------------------- admin

    public function test_admin_pages_require_login(): void
    {
        foreach (['/admin', '/admin/employees', '/admin/payroll'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->post('/admin/topics')->assertRedirect(route('login'));
    }

    public function test_admin_login(): void
    {
        $this->post('/login', ['username' => 'admin', 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'admin', 'password' => config('tracker.admin_password')])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/admin')->assertOk()->assertSee('Topics');
        $this->post('/logout')->assertRedirect(route('home'));
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_topic_crud(): void
    {
        $c = $this->channel();

        $this->admin()->post('/admin/topics', [
            'channel_id' => $c->id, 'title' => 'New one', 'link' => 'https://example.com',
        ])->assertSessionHas('ok');
        $t = Topic::where('title', 'New one')->firstOrFail();
        $this->assertSame('Other', $t->category);
        $this->assertSame(10, $t->sort_order);

        $this->admin()->put("/admin/topics/{$t->id}", [
            'title' => 'Renamed', 'category' => 'Cat', 'link' => '', 'is_done' => '1',
        ])->assertSessionHas('ok');
        $t->refresh();
        $this->assertSame('Renamed', $t->title);
        $this->assertTrue($t->is_done);
        $this->assertNotNull($t->completed_at);

        $this->admin()->delete("/admin/topics/{$t->id}");
        $this->assertNull(Topic::find($t->id));

        $this->admin()->post('/admin/topics', ['channel_id' => $c->id, 'title' => ''])
            ->assertSessionHasErrors('title');
    }

    public function test_reference_link_must_be_http_or_https(): void
    {
        $c = $this->channel();

        $this->admin()->post('/admin/topics', ['channel_id' => $c->id, 'title' => 'X', 'link' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('link');
        $this->assertSame(0, Topic::count());
    }

    public function test_admin_can_assign_a_topic_while_adding_it(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        // the add form offers the employee
        $this->admin()->get('/admin/topics?panel=add')->assertOk()
            ->assertSee('Assign to (optional)')
            ->assertSee('Alice Rahman');

        $this->admin()->post('/admin/topics', [
            'channel_id' => $c->id, 'title' => 'Assigned on create', 'employee_id' => $e->id,
        ])->assertSessionHas('ok');

        $t = Topic::where('title', 'Assigned on create')->firstOrFail();
        $this->assertSame($e->id, $t->assigned_to);
        $this->assertNull($t->added_by, 'An admin-assigned topic must not count as employee-added.');

        // It shows up on the employee's assigned list, not their custom list.
        $this->loginAs('alice');
        $this->get('/employee/topics')->assertOk()->assertSee('Assigned on create');
        $this->get('/employee/custom-topics')->assertOk()->assertDontSee('Assigned on create');
    }

    /**
     * The search is client-side, so the test guards the wiring: the input, the
     * script, and a data-search attribute per row holding everything searchable.
     */
    public function test_topic_page_has_a_search_box_wired_to_every_row(): void
    {
        $c = $this->channel('windows');
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);
        $this->topic(['title' => 'Install Ollama on Windows', 'category' => 'Local AI', 'assigned_to' => $e->id]);

        $html = $this->admin()->get('/admin/topics')->assertOk()
            ->assertSee('data-topic-search', false)
            ->assertSee('js/topic-search.js', false)
            ->assertSee('data-topic-group="'.$c->id.'"', false)
            ->assertSee('data-search="Install Ollama on Windows Local AI Windows Alice Rahman"', false)
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'data-search='),
            'Every searchable row needs exactly one data-search attribute.'
        );
    }

    // -------------------------------------------------------------- earnings

    public function test_admin_sees_what_each_employee_earned_per_channel(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $win = $this->channel('windows');
        $lin = $this->channel('linux');
        $alice = $this->employee('alice');
        $bob = $this->employee('bob');

        Rate::create(['channel_id' => $win->id, 'employee_id' => $alice->id, 'amount' => 100]);
        Rate::create(['channel_id' => $lin->id, 'employee_id' => $alice->id, 'amount' => 50]);
        Rate::create(['channel_id' => $win->id, 'employee_id' => $bob->id, 'amount' => 30]);

        // Alice earned from both channels, Bob only from one.
        $this->topic(['channel_id' => $win->id, 'assigned_to' => $alice->id])->markDone();
        $this->topic(['channel_id' => $lin->id, 'assigned_to' => $alice->id])->markDone();
        $this->topic(['channel_id' => $win->id, 'assigned_to' => $bob->id])->markDone();

        $r = $this->admin()->get('/admin/earnings?month=2026-09')->assertOk();
        $r->assertSee('Earnings by creator and channel')
            ->assertSee('Tk 100')   // Alice, windows
            ->assertSee('Tk 50')    // Alice, linux
            ->assertSee('Tk 30');   // Bob, windows

        $rows = $r->viewData('report')['rows'];
        $aliceRow = $rows->firstWhere('employee.id', $alice->id);
        $bobRow = $rows->firstWhere('employee.id', $bob->id);

        // sorted by total earned, so Alice (150) is above Bob (30)
        $this->assertSame($alice->id, $rows->first()['employee']->id);
        $this->assertSame(100.0, $aliceRow['cells'][$win->id]['earned']);
        $this->assertSame(50.0, $aliceRow['cells'][$lin->id]['earned']);
        $this->assertSame(150.0, $aliceRow['earned']);
        $this->assertSame(0.0, $bobRow['cells'][$lin->id]['earned']);
        $this->assertSame(30.0, $bobRow['earned']);
    }

    /**
     * The employee's name in the earnings matrix links to their own day/week/month/
     * year breakdown — the same figures their own dashboard shows them, reused here
     * so the admin does not have to ask the employee what they are looking at.
     */
    public function test_admin_can_view_one_employees_earnings_broken_down_by_day_week_month_and_year(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $win = $this->channel('windows');
        $alice = $this->employee('alice');
        Rate::create(['channel_id' => $win->id, 'employee_id' => $alice->id, 'amount' => 40]);

        $this->topic(['channel_id' => $win->id, 'assigned_to' => $alice->id])->markDone();

        $this->admin()->get('/admin/earnings')->assertOk()
            ->assertSee(route('admin.employees.earnings', $alice), false);

        $show = $this->admin()->get(route('admin.employees.earnings', $alice))->assertOk()
            ->assertSee('Alice')
            ->assertSee('Tk 40');

        $this->assertSame(1, $show->viewData('summary')['today']['count']);
        $this->assertSame(40.0, $show->viewData('summary')['today']['amount']);
        $this->assertSame(1, $show->viewData('summary')['all']['count']);
        $this->assertSame(40.0, $show->viewData('summary')['all']['amount']);

        $day = $show->viewData('history')['day'];
        $this->assertSame(1, $day[0]['count']);
        $this->assertSame(40.0, $day[0]['amount']);
    }

    /**
     * A topic completed before any rate was ever configured for that pair earns
     * Tk 0, which used to read identically to "nothing happened here" — the cell
     * fell into the em-dash branch and the completion vanished from the matrix.
     * An explicit Tk 0 rate had the same problem the other way round: it looked
     * exactly like no rate at all, so the employee dropped out of the report
     * entirely once they had no completions left to keep them in it.
     */
    public function test_a_completion_with_no_rate_still_shows_and_a_zero_rate_still_counts_as_a_rate(): void
    {
        $c = $this->channel();
        $noRateYet = $this->employee('alice');
        $zeroRate = $this->employee('bob');

        // Completed before any rate existed for this pair: earns Tk 0, but it
        // still happened and must not disappear behind the "nothing done" dash.
        $this->topic(['channel_id' => $c->id, 'assigned_to' => $noRateYet->id])->markDone();

        // A rate of exactly zero, on purpose, with nothing completed yet.
        Rate::create(['channel_id' => $c->id, 'employee_id' => $zeroRate->id, 'amount' => 0]);

        $r = $this->admin()->get('/admin/earnings')->assertOk();

        $rows = $r->viewData('report')['rows'];
        $this->assertSame(1, $rows->firstWhere('employee.id', $noRateYet->id)['cells'][$c->id]['done'],
            'The completion must be counted even though it earned nothing.');
        $this->assertNotNull($rows->firstWhere('employee.id', $zeroRate->id),
            'An employee with an explicit Tk 0 rate must still appear in the report.');

        $r->assertSee('1 topic');
    }

    public function test_admin_earnings_can_be_switched_to_all_time(): void
    {
        $c = $this->channel();
        $e = $this->employee();
        Rate::create(['channel_id' => $c->id, 'employee_id' => $e->id, 'amount' => 10]);

        $this->topic(['channel_id' => $c->id, 'assigned_to' => $e->id, 'is_done' => true,
            'completed_by' => $e->id, 'earned_amount' => 10, 'completed_at' => '2026-01-05 10:00'])->save();

        // January is outside the current month
        $this->admin()->get('/admin/earnings')->assertViewHas('report', fn ($r) => $r['periodEarned'] === 0.0);
        $this->admin()->get('/admin/earnings?month=2026-01')
            ->assertViewHas('report', fn ($r) => $r['periodEarned'] === 10.0);
        $this->admin()->get('/admin/earnings?month=all')
            ->assertOk()
            ->assertViewHas('allTime', true)
            ->assertViewHas('report', fn ($r) => $r['periodEarned'] === 10.0 && $r['allEarned'] === 10.0);
    }

    /** A cleared month input submits "", which used to reach Carbon and 500. */
    public function test_admin_earnings_month_input_is_forgiving(): void
    {
        $this->admin()->get('/admin/earnings?month=')
            ->assertOk()
            ->assertViewHas('report', fn ($r) => $r['month'] === now()->format('Y-m'));

        // a typo must not quietly show the wrong month's numbers
        $this->admin()->get('/admin/earnings?month=garbage')->assertSessionHasErrors('month');
    }

    public function test_only_the_admin_can_see_admin_earnings(): void
    {
        $this->get('/admin/earnings')->assertRedirect(route('login'));

        $this->employee('emp');
        $this->loginAs('emp');
        $this->get('/admin/earnings')->assertRedirect(route('login'));
    }

    public function test_admin_can_set_pay_rates_from_the_dashboard(): void
    {
        $c = $this->channel();
        $e = $this->employee('emp');

        $this->admin();

        Livewire::test(RateEditor::class)
            ->assertSee('Pay rates')
            ->call('toggle')
            ->assertSeeHtml('wire:model="rates.'.$c->id.'-'.$e->id.'"')
            ->set('rates.'.$c->id.'-'.$e->id, '12.25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertSame(12.25, (float) Rate::where('channel_id', $c->id)->where('employee_id', $e->id)->value('amount'));
    }

    public function test_adding_a_topic_without_an_employee_leaves_it_unassigned(): void
    {
        $c = $this->channel();
        $this->employee();

        foreach ([null, ''] as $blank) {
            $this->admin()->post('/admin/topics', [
                'channel_id' => $c->id, 'title' => 'No owner', 'employee_id' => $blank,
            ])->assertSessionHas('ok');
        }

        $this->assertSame(2, Topic::whereNull('assigned_to')->count());
    }

    public function test_adding_a_topic_rejects_an_unknown_employee(): void
    {
        $c = $this->channel();

        $this->admin()->post('/admin/topics', ['channel_id' => $c->id, 'title' => 'Ghost', 'employee_id' => 999])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Topic::count());
    }

    public function test_recently_added_section_lists_only_the_last_three_days_newest_first(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');

        $old = $this->topic(['title' => 'Ancient', 'assigned_to' => $e->id]);
        $old->forceFill(['created_at' => now()->subDays(4)])->save();

        // A minute either side of the 3-day line: the newer one is in, the older is out.
        $justOutside = $this->topic(['title' => 'Just outside', 'assigned_to' => $e->id]);
        $justOutside->forceFill(['created_at' => now()->subDays(3)->subMinute()])->save();

        $justInside = $this->topic(['title' => 'Three days old', 'assigned_to' => $e->id]);
        $justInside->forceFill(['created_at' => now()->subDays(3)->addMinute()])->save();

        $newest = $this->topic(['title' => 'Newest', 'assigned_to' => $e->id]);
        $newest->forceFill(['created_at' => now()])->save();

        $middle = $this->topic(['title' => 'Middle', 'assigned_to' => $e->id]);
        $middle->forceFill(['created_at' => now()->subDay()])->save();

        $this->admin();
        $c = Livewire::test(AdminTopics::class, ['panel' => 'add']);

        $items = $c->viewData('recent');

        $this->assertSame(3, $items['total']);
        $this->assertSame(
            ['Newest', 'Middle', 'Three days old'],
            $items['items']->pluck('title')->all(),
            'Recent topics must be newest first and exclude anything older than 3 days.'
        );

        // The strip repeats them; the grouped list below still carries every topic.
        $c->assertSee('Recently added')->assertSee('Newest');
        $all = $c->viewData('topics');
        $this->assertCount(5, $all);
        $this->assertContains('Ancient', $all->pluck('title')->all());
    }

    public function test_recently_added_section_is_hidden_when_everything_is_older_than_three_days(): void
    {
        $t = $this->topic(['title' => 'Ancient']);
        $t->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->admin();
        $c = Livewire::test(AdminTopics::class);

        $this->assertSame(0, $c->viewData('recent')['total']);
        $c->assertDontSee('Recently added');
    }

    public function test_recently_added_section_caps_the_list_but_counts_the_rest(): void
    {
        $this->channel();

        for ($i = 1; $i <= Topic::RECENT_LIMIT + 4; $i++) {
            $this->topic(['title' => "Topic $i"]);
        }

        $this->admin();
        $recent = Livewire::test(AdminTopics::class)->viewData('recent');

        $this->assertSame(Topic::RECENT_LIMIT + 4, $recent['total']);
        $this->assertCount(Topic::RECENT_LIMIT, $recent['items']);
        $this->assertSame('Topic '.Topic::RECENT_LIMIT + 4, $recent['items']->first()->title);
    }

    public function test_recently_added_shows_the_assignee_and_survives_ties_on_created_at(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        $first = $this->topic(['title' => 'Tie A', 'assigned_to' => $e->id]);
        $second = $this->topic(['title' => 'Tie B', 'assigned_to' => $e->id]);
        $first->forceFill(['created_at' => now()])->save();
        $second->forceFill(['created_at' => now()])->save();

        $this->admin();
        $lw = Livewire::test(AdminTopics::class);
        $lw->assertSee('Alice Rahman');

        $this->assertSame(
            ['Tie B', 'Tie A'],
            $lw->viewData('recent')['items']->pluck('title')->all(),
            'Same-second topics must fall back to the higher id.'
        );
    }

    /**
     * The strip must say who added each topic, for every role — `added_by` alone
     * cannot answer that, because it is an employee key that stays null for staff.
     */
    public function test_recently_added_says_who_added_the_topic_for_every_role(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        // employee adds their own
        $this->loginAs('alice');
        $this->post('/employee/topics', ['channel_id' => $c->id, 'title' => 'By the employee'])->assertRedirect();
        $this->post('/logout');
        $byEmployee = Topic::where('title', 'By the employee')->firstOrFail();
        $this->assertSame('employee:'.$e->id, $byEmployee->added_by_key,
            'Every topic an employee adds must carry a stable key, not just the FK, so a later rename does not lock them out of the strip.');

        // admin adds one
        $this->admin()->post('/admin/topics', ['channel_id' => $c->id, 'title' => 'By the admin'])->assertRedirect();
        $byAdmin = Topic::where('title', 'By the admin')->firstOrFail();
        $this->assertNull($byAdmin->added_by, 'An admin topic must stay off the employee Custom Topics list.');
        $this->assertSame(Admin::first()->name, $byAdmin->added_by_label);
        $this->assertSame('admin:'.Admin::first()->id, $byAdmin->added_by_key);

        // manager adds one in their own channel. Logged out of the admin guard
        // first: actingAs() only sets who is authenticated, it does not also log
        // anyone else out, and currentAdderKey() (correctly, for real requests,
        // where LoginController guarantees only one guard is ever signed in)
        // checks the admin guard before the manager one.
        $this->post('/logout');
        $m = $this->managerChannels($c);
        $m->update(['name' => 'Musa Manager']);
        $this->actingAs($m, 'manager')
            ->post('/manager/topics', ['channel_id' => $c->id, 'title' => 'By the manager'])->assertRedirect();
        $byManager = Topic::where('title', 'By the manager')->firstOrFail();
        $this->assertSame('manager:'.$m->id, $byManager->added_by_key);

        $this->admin();
        $lw = Livewire::test(AdminTopics::class, ['panel' => 'add']);
        $labels = $lw->viewData('recent')['items']->mapWithKeys(
            fn ($t) => [$t->title => $t->addedByLabel()]
        )->all();

        $this->assertSame('Alice Rahman', $labels['By the employee']);
        $this->assertSame(Admin::first()->name, $labels['By the admin']);
        $this->assertSame('Musa Manager', $labels['By the manager']);

        // and it is actually rendered
        $lw->assertSee('Alice Rahman')->assertSee('by <b>'.Admin::first()->name.'</b>', false)
            ->assertSee('by <b>Musa Manager</b>', false);
    }

    /** A pre-existing employee topic has no label, so fall back to the relation. */
    public function test_added_by_label_falls_back_to_the_employee_relation(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        $t = $this->topic(['title' => 'Legacy row', 'assigned_to' => $e->id, 'added_by' => $e->id]);
        $this->assertNull($t->added_by_label);
        $this->assertSame('Alice Rahman', $t->addedByLabel());

        // nothing to attribute, so the strip says nothing rather than guessing
        $bare = $this->topic(['title' => 'Seeded row']);
        $this->assertNull($bare->addedByLabel());

        $this->admin()->get('/admin/topics')->assertOk()
            ->assertSee('Legacy row')
            ->assertDontSee('by <b></b>', false);
    }

    public function test_employee_added_topic_is_theirs_and_labelled_with_their_name(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        $this->loginAs('alice');
        $this->post('/employee/topics', ['channel_id' => $c->id, 'title' => 'My own idea', 'link' => 'https://example.com'])
            ->assertRedirect(route('employee.custom-topics', ['channel' => $c->id]));

        $t = Topic::where('title', 'My own idea')->firstOrFail();
        $this->assertSame($e->id, $t->assigned_to, 'An employee topic must be assigned to the adder.');
        $this->assertSame($e->id, $t->added_by);
        $this->assertSame('Alice Rahman', $t->added_by_label);
        $this->assertSame(Topic::categoryFor('My own idea'), $t->category,
            'category must stay a real classification — the admin/manager tables show it as-is.');

        // The tracker gives an employee's own topics their own section, named after
        // them, driven by added_by_label rather than by category.
        $this->post('/logout');
        $this->get('/tracker')->assertOk()
            ->assertSee('Alice Rahman')
            ->assertDontSee('Added by employee');
    }

    /**
     * Within a channel, what an employee added themselves leads the section list,
     * ahead of the regular category sections — regardless of where their own
     * (now perfectly ordinary) category would otherwise have sorted alphabetically.
     */
    public function test_employee_added_section_comes_before_the_channels_own_categories(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        // "AI Tools" and "Other" would both sort ahead of "Windows" alphabetically,
        // and Alice's own category (from categoryFor('My own idea')) is "Other" —
        // so alphabetical order alone would not put her section first.
        $this->topic(['channel_id' => $c->id, 'category' => 'AI Tools', 'title' => 'A regular AI topic']);
        $this->topic(['channel_id' => $c->id, 'category' => 'Windows', 'title' => 'A regular Windows topic']);

        $this->loginAs('alice');
        $this->post('/employee/topics', ['channel_id' => $c->id, 'title' => 'My own idea']);
        $this->post('/logout');

        $r = $this->get('/tracker')->assertOk();

        $labels = collect($r->viewData('groups'))->pluck('category')->all();

        $this->assertSame(['Alice Rahman', 'AI Tools', 'Windows'], $labels);
    }

    public function test_migration_recategorizes_existing_employee_topics_instead_of_naming_them(): void
    {
        $c = $this->channel();
        $e = $this->employee('alice');
        $e->update(['name' => 'Alice Rahman']);

        $legacy = $this->topic(['title' => 'Legacy', 'assigned_to' => $e->id, 'added_by' => $e->id,
            'category' => 'Added by employee']);
        $real = $this->topic(['title' => 'Seeded', 'category' => 'Windows']);
        $named = $this->topic(['title' => 'Named', 'added_by' => $e->id, 'category' => 'Windows']);

        $migration = require database_path('migrations/2026_09_28_000001_use_adder_name_as_topic_category.php');
        $migration->up();

        $this->assertSame(Topic::categoryFor('Legacy'), $legacy->fresh()->category);
        $this->assertSame('Windows', $real->fresh()->category, 'Unrelated categories must be left alone.');
        $this->assertSame('Windows', $named->fresh()->category);

        // down() is a no-op: nothing distinguishes a row it relabelled from a
        // topic an employee added completely normally afterwards, which has the
        // very same category === categoryFor(title) shape by design. Guessing
        // would revert that real data in exchange for restoring a placeholder
        // string nobody wants back.
        $migration->down();
        $this->assertSame(Topic::categoryFor('Legacy'), $legacy->fresh()->category);
        $this->assertSame('Windows', $real->fresh()->category);
    }

    public function test_admin_employee_rates_and_assignment(): void
    {
        $c = $this->channel();
        $t = $this->topic();

        $this->admin()->post('/admin/employees', ['name' => 'Rahim', 'username' => 'rahim', 'password' => 'secret'])
            ->assertSessionHas('ok');
        $e = Employee::where('username', 'rahim')->firstOrFail();
        $this->assertNotSame('secret', $e->password);

        $this->admin()->post('/admin/employees', ['name' => 'Dup', 'username' => 'rahim', 'password' => 'x'])
            ->assertSessionHasErrors('username');

        $this->admin()->put('/admin/rates', ['channels' => [$c->id => [$e->id => '25.50']]])
            ->assertSessionHas('ok');
        $this->assertSame('25.50', Rate::first()->amount);

        $this->admin()->put("/admin/topics/{$t->id}/assign", ['employee_id' => $e->id]);
        $this->assertSame($e->id, $t->fresh()->assigned_to);

        $this->admin()->get('/admin/employees')->assertOk()->assertSee('25.50');

        // password left blank keeps the old hash
        $hash = $e->password;
        $this->admin()->put("/admin/employees/{$e->id}", ['name' => 'Rahim R', 'username' => 'rahim', 'password' => '', 'is_active' => '1']);
        $this->assertSame($hash, $e->fresh()->password);

        // deleting the employee unassigns topics and drops rates
        $this->admin()->delete("/admin/employees/{$e->id}");
        $this->assertNull($t->fresh()->assigned_to);
        $this->assertSame(0, Rate::count());
    }

    public function test_completed_topics_cannot_be_reassigned(): void
    {
        $a = $this->employee('a');
        $b = $this->employee('b');
        $t = $this->topic(['assigned_to' => $a->id]);
        $t->markDone();

        $this->admin()->put("/admin/topics/{$t->id}/assign", ['employee_id' => $b->id])
            ->assertSessionHasErrors('assign');
        $this->assertSame($a->id, $t->fresh()->assigned_to);
    }

    public function test_only_admin_can_manage_employees(): void
    {
        $payload = ['name' => 'Sneaky', 'username' => 'sneaky', 'password' => 'pw'];

        $this->post('/admin/employees', $payload)->assertRedirect(route('login'));
        $this->get('/admin/employees')->assertRedirect(route('login'));

        $emp = $this->employee();
        $this->loginAs('emp');
        $this->post('/admin/employees', $payload)->assertRedirect(route('login'));
        $this->put("/admin/employees/{$emp->id}", ['name' => 'Hacked', 'username' => 'emp'])->assertRedirect(route('login'));
        $this->delete("/admin/employees/{$emp->id}")->assertRedirect(route('login'));
        $this->put('/admin/rates', ['channels' => []])->assertRedirect(route('login'));
        $this->get('/admin/payroll')->assertRedirect(route('login'));

        $this->assertSame(1, Employee::count());
        $this->assertSame('Emp', $emp->fresh()->name);
        $this->get('/register')->assertNotFound();
    }

    // ----------------------------------------------------------------- account

    public function test_admin_can_change_their_own_username_and_password(): void
    {
        $admin = Admin::firstOrFail();

        $this->admin()->get('/admin/account')->assertOk()->assertSee('Current password');

        $this->admin()->put('/admin/account', [
            'name' => 'Head Admin',
            'username' => 'chief',
            'current_password' => $this->adminPassword(),
            'password' => 'a-much-better-secret',
            'password_confirmation' => 'a-much-better-secret',
        ])->assertRedirect(route('admin.account'))->assertSessionHas('ok');

        $admin->refresh();
        $this->assertSame('Head Admin', $admin->name);
        $this->assertSame('chief', $admin->username);
        $this->assertTrue(Hash::check('a-much-better-secret', $admin->password));
    }

    /** The new username has to actually work on the login page, in any case. */
    public function test_admin_can_sign_in_with_their_new_username(): void
    {
        $this->admin()->put('/admin/account', [
            'name' => 'Admin', 'username' => 'chief', 'current_password' => $this->adminPassword(),
        ])->assertSessionHas('ok');

        $this->post('/logout');
        $this->post('/login', ['username' => 'CHIEF', 'password' => $this->adminPassword()])
            ->assertRedirect(route('admin.dashboard'));

        $this->post('/logout');
        $this->post('/login', ['username' => (string) config('tracker.admin_username'), 'password' => $this->adminPassword()])
            ->assertSessionHasErrors('username');
    }

    public function test_admin_cannot_change_their_details_without_the_current_password(): void
    {
        $admin = Admin::firstOrFail();

        $this->admin()->put('/admin/account', [
            'name' => 'X', 'username' => 'chief', 'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($admin->username, $admin->fresh()->username);
    }

    public function test_admin_cannot_take_an_employee_or_manager_username(): void
    {
        $admin = Admin::firstOrFail();
        $this->employee('emp');

        $this->admin()->put('/admin/account', [
            'name' => 'X', 'username' => 'EMP', 'current_password' => $this->adminPassword(),
        ])->assertSessionHasErrors('username');

        $this->assertSame($admin->username, $admin->fresh()->username);
    }

    public function test_employee_cannot_reach_the_admin_account_page(): void
    {
        $adminBefore = Admin::firstOrFail()->username;
        $this->employee('emp');

        $this->get('/admin/account')->assertRedirect(route('login'));
        $this->put('/admin/account', ['name' => 'X', 'username' => 'chief', 'current_password' => 'pw'])
            ->assertRedirect(route('login'));

        $this->assertSame($adminBefore, Admin::firstOrFail()->username);
    }

    private function adminPassword(): string
    {
        return (string) config('tracker.admin_password');
    }

    public function test_employee_cannot_take_the_admin_username(): void
    {
        $this->admin()->post('/admin/employees', ['name' => 'X', 'username' => 'admin', 'password' => 'pw'])
            ->assertSessionHasErrors('username');
        $this->assertSame(0, Employee::count());
    }

    // ------------------------------------------------------------------- login

    public function test_unified_login_routes_each_role_and_guards_the_other(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->employee();

        $this->post('/login', ['username' => 'emp', 'password' => 'pw'])->assertRedirect(route('employee.dashboard'));
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('employee.dashboard'));
        $this->post('/logout');

        $this->post('/login', ['username' => 'ADMIN', 'password' => config('tracker.admin_password')])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/employee')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('admin.dashboard'));
    }

    public function test_inactive_employee_cannot_log_in(): void
    {
        $this->employee('off', 'pw', ['is_active' => false]);

        $this->post('/login', ['username' => 'off', 'password' => 'pw'])->assertSessionHasErrors('username');
    }

    /** A browser holds one identity: a later login must not inherit the earlier one's powers. */
    public function test_employee_login_after_the_admin_does_not_keep_admin_rights(): void
    {
        $this->employee();
        $t = $this->topic();

        $this->post('/login', ['username' => 'admin', 'password' => config('tracker.admin_password')])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/admin')->assertOk();

        $this->post('/login', ['username' => 'emp', 'password' => 'pw'])
            ->assertRedirect(route('employee.dashboard'));

        $this->assertFalse((bool) session('tracker_admin'), 'The admin flag survived an employee login.');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/employees')->assertRedirect(route('login'));
        $this->get('/admin/payroll')->assertRedirect(route('login'));
        $this->postJson("/topics/{$t->id}/toggle")->assertForbidden();
        $this->assertFalse($t->fresh()->is_done);
    }

    public function test_admin_login_after_an_employee_does_not_keep_employee_rights(): void
    {
        $this->employee();

        $this->post('/login', ['username' => 'emp', 'password' => 'pw'])
            ->assertRedirect(route('employee.dashboard'));
        $this->get('/employee')->assertOk();

        $this->post('/login', ['username' => 'admin', 'password' => config('tracker.admin_password')])
            ->assertRedirect(route('admin.dashboard'));

        $this->get('/employee')->assertRedirect(route('login'));
        $this->get('/admin')->assertOk();
    }

    public function test_manager_login_after_the_admin_does_not_keep_admin_rights(): void
    {
        $ch = $this->channel('linux');
        $m = Manager::create(['name' => 'M', 'username' => 'mgr', 'password' => 'pw']);
        $m->channels()->sync([$ch->id]);
        $t = $this->topic();

        $this->post('/login', ['username' => 'admin', 'password' => config('tracker.admin_password')]);
        $this->post('/login', ['username' => 'mgr', 'password' => 'pw'])
            ->assertRedirect(route('manager.dashboard'));

        $this->assertFalse((bool) session('tracker_admin'), 'The admin flag survived a manager login.');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/managers')->assertRedirect(route('login'));
        $this->postJson("/topics/{$t->id}/toggle")->assertForbidden();
    }

    /**
     * An unset ADMIN_PASSWORD must lock the panel, never fall back to a shipped
     * default. The admin now authenticates against the admins table, so the test
     * re-seeds that table the way a fresh install with no ADMIN_PASSWORD would.
     */
    public function test_admin_login_fails_closed_when_no_password_is_configured(): void
    {
        $this->reseedAdmins('');

        foreach (['', '   '] as $blank) {
            $this->post('/login', ['username' => config('tracker.admin_username'), 'password' => $blank])
                ->assertSessionHasErrors('password');
        }

        foreach (['null', 'admin', 'admin123', 'password', 'secret', (string) config('tracker.admin_username')] as $guess) {
            $this->post('/login', ['username' => config('tracker.admin_username'), 'password' => $guess])
                ->assertSessionHasErrors('username');
        }

        $this->assertGuest('admin');
    }

    /**
     * The existing environment password is carried into the admins table, so
     * deploying the migration does not lock the admin out of their own panel.
     */
    public function test_the_environment_admin_password_survives_the_migration(): void
    {
        $password = (string) config('tracker.admin_password');

        $this->reseedAdmins($password);

        $this->assertNotSame($password, Admin::first()->password, 'The admin password is stored in plaintext.');
        $this->assertTrue(Hash::check($password, Admin::first()->password));

        $this->post('/login', ['username' => config('tracker.admin_username'), 'password' => $password])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated('admin');
    }

    /** Rebuild the admins table from a given ADMIN_PASSWORD, as the migration does. */
    private function reseedAdmins(string $password): void
    {
        config(['tracker.admin_password' => $password]);
        Admin::forgetTableCache();

        Schema::drop('admins');
        $this->createAdminsTable();
    }

    /** Run just the create half of the migration, for a table a test already dropped. */
    private function createAdminsTable(): void
    {
        (require database_path('migrations/2026_09_29_000001_create_admins_table.php'))->up();
        Admin::forgetTableCache();
    }

    public function test_employee_cannot_be_renamed_to_the_admin_username_in_any_case(): void
    {
        $e = $this->employee('emp');

        $this->admin()->put("/admin/employees/{$e->id}", ['name' => 'X', 'username' => 'AdMiN'])
            ->assertSessionHasErrors('username');

        $this->assertSame('emp', $e->fresh()->username);
    }

    public function test_deactivated_employee_is_signed_out_mid_session(): void
    {
        $e = $this->employee();
        $t = $this->topic(['assigned_to' => $e->id, 'video_url' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa']);
        $this->loginAs('emp');
        $this->get('/employee')->assertOk();

        $e->update(['is_active' => false]);
        Auth::forgetGuards(); // a real request starts with a fresh guard

        $this->post("/employee/topics/{$t->id}/toggle")->assertRedirect(route('login'));
        $this->assertFalse($t->fresh()->is_done);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['username' => 'admin', 'password' => 'nope']);
        }
        $this->post('/login', ['username' => 'admin', 'password' => config('tracker.admin_password')])
            ->assertStatus(429);
    }

    // ---------------------------------------------------------- YouTube parsing

    public function test_youtube_video_id_parsing(): void
    {
        $id = 'dQw4w9WgXcQ';
        foreach ([
            "https://www.youtube.com/watch?v=$id",
            "http://youtube.com/watch?v=$id&t=10s",
            "https://m.youtube.com/watch?v=$id",
            "https://youtu.be/$id?si=abc",
            "https://www.youtube.com/shorts/$id",
            "https://www.youtube.com/embed/$id",
            "youtube.com/watch?v=$id",
        ] as $url) {
            $this->assertSame($id, YouTube::videoId($url), $url);
        }

        foreach ([
            '', 'hello', 'https://vimeo.com/123456789', 'https://www.youtube.com/', 'https://www.youtube.com/@channel',
            'https://www.youtube.com/playlist?list=PL123', 'https://www.youtube.com/watch?v=short',
            'https://evil.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ',
        ] as $bad) {
            $this->assertNull(YouTube::videoId($bad), $bad);
        }
    }

    // ---------------------------------------------------- employee video + earn

    public function test_video_preview_returns_title(): void
    {
        $this->fakeYoutube('Install Ollama Fast');

        $this->getJson('/video-preview?url='.urlencode('https://youtu.be/dQw4w9WgXcQ'))
            ->assertOk()->assertJson(['ok' => true, 'title' => 'Install Ollama Fast']);

        $this->getJson('/video-preview?url='.urlencode('not a link'))
            ->assertJson(['ok' => false]);
    }

    public function test_video_preview_is_stateless_so_it_cannot_wipe_flash_messages(): void
    {
        $this->fakeYoutube();

        $response = $this->getJson('/video-preview?url='.urlencode('https://youtu.be/dQw4w9WgXcQ'));

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies(), 'preview must not set a session/CSRF cookie');
    }

    public function test_unknown_video_is_rejected_but_youtube_outage_is_tolerated(): void
    {
        $e = $this->employee();
        $t = $this->topic(['assigned_to' => $e->id]);
        $this->loginAs('emp');

        Http::fake(['www.youtube.com/oembed*' => Http::sequence()->push('Bad Request', 400)->push('oops', 503)]);

        $this->post("/employee/topics/{$t->id}/video", ['video_url' => 'https://youtu.be/aaaaaaaaaaa'])
            ->assertSessionHasErrors('video');
        $this->assertNull($t->fresh()->video_url);

        $this->post("/employee/topics/{$t->id}/video", ['video_url' => 'https://youtu.be/aaaaaaaaaaa'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://www.youtube.com/watch?v=aaaaaaaaaaa', $t->fresh()->video_url);
        $this->assertNull($t->fresh()->video_title);
    }

    public function test_employee_flow_video_is_required_then_done_is_locked(): void
    {
        $this->fakeYoutube('Ollama on Windows in 5 minutes');
        $c = $this->channel();
        $me = $this->employee('me', 'pw123');
        Rate::create(['channel_id' => $c->id, 'employee_id' => $me->id, 'amount' => 100]);
        $t = $this->topic(['title' => 'Mine', 'assigned_to' => $me->id]);

        $this->get('/employee')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'me', 'password' => 'bad'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'me', 'password' => 'pw123'])->assertRedirect(route('employee.dashboard'));

        // cannot tick without a video
        $this->post("/employee/topics/{$t->id}/toggle")->assertSessionHasErrors('video');
        $this->assertFalse($t->fresh()->is_done);

        // bad link
        $this->post("/employee/topics/{$t->id}/video", ['video_url' => 'https://vimeo.com/1'])
            ->assertSessionHasErrors('video');

        // good link -> title stored and shown
        $this->post("/employee/topics/{$t->id}/video", ['video_url' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->assertSessionHas('ok');
        $t->refresh();
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $t->video_url);
        $this->assertSame('Ollama on Windows in 5 minutes', $t->video_title);
        $this->get('/employee')->assertOk();

        // tick -> done, dated, earns the rate
        $this->post("/employee/topics/{$t->id}/toggle")->assertSessionHas('ok');
        $t->refresh();
        $this->assertTrue($t->is_done);
        $this->assertNotNull($t->completed_at);
        $this->assertSame($me->id, $t->completed_by);
        $this->assertSame('100.00', $t->earned_amount);
        $this->get('/employee')->assertSee('Tk 100')->assertSee($t->completed_at->format('j M Y'));

        // video link is locked while done, but it can still be ticked back open
        $this->post("/employee/topics/{$t->id}/video", ['video_url' => 'https://youtu.be/bbbbbbbbbbb'])
            ->assertSessionHasErrors('video');
        $t->refresh();
        $this->assertTrue($t->is_done);
        $this->assertSame('100.00', $t->earned_amount);
        $this->assertStringContainsString('dQw4w9WgXcQ', $t->video_url);

        $this->post("/employee/topics/{$t->id}/toggle")->assertSessionHas('ok');
        $t->refresh();
        $this->assertFalse($t->is_done);
        $this->assertNull($t->completed_at);
        $this->assertSame(0.0, (float) $t->earned_amount);
    }

    public function test_same_video_cannot_be_used_for_two_topics(): void
    {
        $this->fakeYoutube();
        $e = $this->employee();
        $t1 = $this->topic(['title' => 'One', 'assigned_to' => $e->id]);
        $t2 = $this->topic(['title' => 'Two', 'assigned_to' => $e->id]);
        $this->loginAs('emp');

        $this->post("/employee/topics/{$t1->id}/video", ['video_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertSessionHas('ok');
        $this->post("/employee/topics/{$t2->id}/video", ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5'])
            ->assertSessionHasErrors('video');
        $this->assertNull($t2->fresh()->video_url);
    }

    public function test_employee_cannot_touch_other_employees_topics(): void
    {
        $this->fakeYoutube();
        $this->employee('me');
        $other = $this->employee('other');
        $theirs = $this->topic(['title' => 'Theirs', 'assigned_to' => $other->id, 'video_url' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa']);
        $this->loginAs('me');

        $this->get('/employee')->assertDontSee('Theirs');
        $this->post("/employee/topics/{$theirs->id}/toggle")->assertForbidden();
        $this->post("/employee/topics/{$theirs->id}/video", ['video_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertForbidden();
        $this->assertFalse($theirs->fresh()->is_done);
    }

    public function test_dashboard_tolerates_done_topic_without_a_date(): void
    {
        $e = $this->employee();
        $this->topic(['assigned_to' => $e->id, 'is_done' => true]); // legacy row: done, no completed_at
        $this->loginAs('emp');

        $this->get('/employee')->assertOk();
    }

    /**
     * Before the admins table is migrated the admin is authenticated by a session
     * flag, so there is no row for the account page to edit. That must be an
     * explained redirect, not a bare 403 on a page the admin may otherwise open.
     */
    public function test_admin_account_page_explains_itself_before_the_migration_runs(): void
    {
        Admin::forgetTableCache();
        Schema::drop('admins');

        try {
            $this->withSession(['tracker_admin' => true])
                ->get('/admin')
                ->assertOk()  // the legacy session still opens the dashboard
                ->assertDontSee('My account');  // but there is no account link to follow

            $this->withSession(['tracker_admin' => true])
                ->get('/admin/account')
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('account');

            $this->withSession(['tracker_admin' => true])
                ->put('/admin/account', [])
                ->assertRedirect(route('login'));
        } finally {
            $this->createAdminsTable();
        }
    }

    // -------------------------------------------------------------- dashboards

    public function test_admin_dashboard_summarises_every_channel(): void
    {
        $busy = $this->channel('windows');
        $quiet = $this->channel('linux');
        $e = $this->employee('alice');
        Rate::create(['channel_id' => $busy->id, 'employee_id' => $e->id, 'amount' => 100]);

        $this->topic(['channel_id' => $busy->id, 'assigned_to' => $e->id])->markDone();
        $this->topic(['channel_id' => $busy->id, 'assigned_to' => $e->id]);          // still pending
        $this->topic(['channel_id' => $quiet->id, 'assigned_to' => $e->id]);          // unassigned elsewhere? no - pending

        $this->admin();
        $r = Livewire::test(AdminDashboard::class);

        $rows = $r->viewData('channelStats');
        $this->assertCount(2, $rows);

        $busyRow = $rows->firstWhere('channel.id', $busy->id);
        $quietRow = $rows->firstWhere('channel.id', $quiet->id);

        $this->assertSame(2, $busyRow['total']);
        $this->assertSame(1, $busyRow['done']);
        $this->assertSame(1, $busyRow['pending']);
        $this->assertSame(100.0, $busyRow['earned']);
        $this->assertSame(1, $quietRow['total']);
        $this->assertSame(0.0, $quietRow['earned']);
    }

    /**
     * The dashboard's "+ Add topic" button used to link to the bare Topics page,
     * which defaults to the Show Topic list rather than the form — so clicking it
     * landed the admin on the very page they were trying to get away from.
     */
    public function test_the_dashboard_add_topic_button_opens_the_add_topic_page(): void
    {
        $this->admin();

        $this->get('/admin')->assertOk()
            ->assertSee(route('admin.topics.index', ['panel' => 'add']), false);
    }

    public function test_manager_dashboard_only_counts_their_own_channels(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $e = $this->employee();
        $m = $this->managerChannels($mine);

        $this->topic(['channel_id' => $mine->id, 'assigned_to' => $e->id])->markDone();
        $this->topic(['channel_id' => $other->id, 'assigned_to' => $e->id]);

        $r = $this->actingAs($m, 'manager')->get('/manager')->assertOk();

        $this->assertSame([$mine->id], $r->viewData('channelStats')->pluck('channel.id')->all());
        $this->assertSame(1, $r->viewData('total'));
        $this->assertSame(1, $r->viewData('done'));
    }

    public function test_manager_dashboard_handles_a_manager_with_no_channels(): void
    {
        $m = $this->managerChannels();

        $r = $this->actingAs($m, 'manager')->get('/manager')->assertOk();
        $r->assertViewHas('channelStats', fn ($s) => $s->isEmpty());
    }

    public function test_dashboard_pages_use_the_shared_responsive_stylesheet(): void
    {
        // The link lives in the shared head partial, which both layouts include.
        $this->assertStringContainsString(
            'css/dashboard.css',
            file_get_contents(resource_path('views/partials/_head.blade.php'))
        );

        foreach (['app', 'livewire'] as $layout) {
            $this->assertStringContainsString(
                "partials._head",
                file_get_contents(resource_path("views/layouts/{$layout}.blade.php")),
                "layouts/{$layout}.blade.php must use the shared head"
            );
        }

        $c = $this->channel();
        $e = $this->employee();
        Rate::create(['channel_id' => $c->id, 'employee_id' => $e->id, 'amount' => 20]);
        $this->topic(['channel_id' => $c->id, 'assigned_to' => $e->id])->markDone();

        // channel cards on the dashboard, scrollable matrix on the earnings page
        $this->admin()->get('/admin')->assertSee('css/dashboard.css')->assertSee('class="chans"', false);
        $this->admin()->get('/admin/earnings')->assertSee('class="dash-table-scroll"', false);

        // the shared stylesheet carries the mobile breakpoints
        $css = file_get_contents(public_path('css/dashboard.css'));
        $this->assertStringContainsString('@media(max-width:700px)', $css);
        $this->assertStringContainsString('.hide-sm', $css);

        // and no view is still carrying its own inline copy of those styles
        foreach (['livewire/admin/dashboard', 'manager/dashboard', 'admin/earnings', 'manager/earnings'] as $view) {
            $this->assertStringNotContainsString(
                '@media',
                file_get_contents(resource_path("views/{$view}.blade.php")),
                "{$view} still has inline CSS; it belongs in dashboard.css"
            );
        }
    }

    /**
     * A handful of responsive bugs found by checking real pages at real widths:
     * the four dashboard stat cards overflowed the content area at any viewport
     * where the fixed sidebar leaves less than 900px to work with (851–1234px);
     * a custom topic's badges and edit/delete buttons could run off the right
     * edge of a phone screen because the row never wrapped; the landing
     * page's nav could do the same; and the tracker's sticky filter bar
     * scrolled up behind the fixed top bar, taking the search box with it. Each
     * fix is one CSS rule, so the regression is a one-line check that the rule
     * is still there.
     */
    public function test_known_responsive_overflow_bugs_stay_fixed(): void
    {
        $app = file_get_contents(public_path('css/app.css'));

        // Four stat cards must drop to two before the sidebar-adjusted content
        // width (viewport - 334px) falls under ~900px, not just the plain
        // viewport width the CSS used to check.
        $this->assertStringContainsString(
            '@media(max-width:900px),(min-width:851px) and (max-width:1234px){.stats2{grid-template-columns:1fr 1fr}}',
            $app
        );

        // A custom topic's row (checkbox, title, badges, edit/delete) wraps
        // instead of running off the edge of a narrow screen.
        $this->assertStringContainsString('.et .row1{display:flex;align-items:flex-start;gap:8px 12px;flex-wrap:wrap}', $app);

        // The tracker's filter bar is the only thing that sticks to the viewport,
        // and the top bar (z-index 25) paints over the bar's own z-index 5 — so
        // behind the sidebar the bar has to be pinned below the top bar, at the
        // same offset the content already starts at, rather than at a flat
        // top:12px. The plain top:12px has to stay for the visitor tracker,
        // which has no top bar above it to clear.
        $this->assertStringContainsString('body.has-sidebar .toolbar{top:var(--shell-top)}', $app);
        $this->assertStringContainsString('position:sticky;top:12px', $app);

        $landing = file_get_contents(resource_path('views/landing.blade.php'));
        $this->assertStringContainsString('flex-wrap:wrap', $landing);
    }

    // ------------------------------------------------------------------ payroll

    public function test_earnings_are_snapshotted_so_later_rate_changes_do_not_rewrite_history(): void
    {
        $c = $this->channel();
        $e = $this->employee();
        Rate::create(['channel_id' => $c->id, 'employee_id' => $e->id, 'amount' => 10]);
        $t = $this->topic(['assigned_to' => $e->id]);
        $t->markDone();

        Rate::where('employee_id', $e->id)->update(['amount' => 999]);

        $this->assertSame('10.00', $t->fresh()->earned_amount);
        $this->admin()->get('/admin/payroll')->assertOk()->assertSee('Tk 10')->assertDontSee('Tk 999');

        // reopening removes the earning
        $t->fresh()->markUndone();
        $this->assertSame('0.00', $t->fresh()->earned_amount);
        $this->assertNull($t->fresh()->completed_by);
    }

    public function test_unassigned_topic_earns_nothing(): void
    {
        $t = $this->topic();
        $t->markDone();

        $this->assertSame('0.00', $t->fresh()->earned_amount);
        $this->assertNull($t->fresh()->completed_by);
    }

    public function test_earnings_summary_and_breakdown_by_day_week_month_year(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00')); // a Wednesday
        $c = $this->channel();
        $e = $this->employee();

        $at = fn (string $when, float $amt) => Topic::create([
            'channel_id' => $c->id, 'title' => "T $when", 'category' => 'x', 'assigned_to' => $e->id,
            'is_done' => true, 'completed_by' => $e->id, 'earned_amount' => $amt,
            'completed_at' => Carbon::parse($when),
        ]);

        $at('2026-09-23 09:00', 10);  // today
        $at('2026-09-22 09:00', 20);  // Tuesday, same week
        $at('2026-09-20 09:00', 40);  // Sunday: previous week, same month
        $at('2026-08-31 09:00', 80);  // last month, same year
        $at('2025-12-31 09:00', 160); // last year

        $rows = Topic::where('completed_by', $e->id)->get();
        $s = Earnings::summary($rows);

        $this->assertSame(10.0, $s['today']['amount']);
        $this->assertSame(30.0, $s['week']['amount']);   // Mon 21 Sep .. Wed 23 Sep
        $this->assertSame(70.0, $s['month']['amount']);
        $this->assertSame(150.0, $s['year']['amount']);
        $this->assertSame(310.0, $s['all']['amount']);
        $this->assertSame(5, $s['all']['count']);

        $days = Earnings::breakdown($rows, 'day');
        $this->assertSame(['Wed, 23 Sep 2026', 'Tue, 22 Sep 2026', 'Sun, 20 Sep 2026', 'Mon, 31 Aug 2026', 'Wed, 31 Dec 2025'], array_column($days, 'label'));

        $weeks = Earnings::breakdown($rows, 'week');
        $this->assertSame(30.0, $weeks[0]['amount']);
        $this->assertSame(40.0, $weeks[1]['amount']);

        $months = Earnings::breakdown($rows, 'month');
        $this->assertSame(['September 2026', 'August 2026', 'December 2025'], array_column($months, 'label'));
        $this->assertSame([70.0, 80.0, 160.0], array_column($months, 'amount'));

        $years = Earnings::breakdown($rows, 'year');
        $this->assertSame([['2026', 4, 150.0], ['2025', 1, 160.0]],
            array_map(fn ($y) => [$y['label'], $y['count'], $y['amount']], $years));

        // the employee sees it on their dashboard
        $this->loginAs('emp');
        $this->get('/employee')->assertOk()->assertSee('Tk 310')->assertSee('September 2026')->assertSee('Tue, 22 Sep 2026');
    }

    private function completed(int $employeeId, string $when, float $amount, int $i = 0): Topic
    {
        return Topic::create([
            'channel_id' => $this->channel()->id, 'title' => "Topic $i", 'category' => 'x', 'assigned_to' => $employeeId,
            'is_done' => true, 'completed_by' => $employeeId, 'earned_amount' => $amount,
            'completed_at' => Carbon::parse($when),
            'video_url' => "https://www.youtube.com/watch?v=aaaaaaaaaa$i", 'video_title' => "Video $i",
        ]);
    }

    public function test_payroll_month_runs_from_the_first_to_the_last_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');

        $this->completed($e->id, '2026-08-31 23:59:59', 100, 1); // last second of August
        $this->completed($e->id, '2026-09-01 00:00:00', 10, 2);  // first second of September
        $this->completed($e->id, '2026-09-30 23:59:59', 20, 3);  // last second of September
        $this->completed($e->id, '2026-10-01 00:00:00', 400, 4); // first second of October

        $sep = $this->admin()->get('/admin/payroll?month=2026-09')->assertOk();
        $sep->assertViewHas('totalEarned', 30.0);
        $sep->assertSee('1 Sep – 30 Sep 2026');

        $this->admin()->get('/admin/payroll?month=2026-08')->assertViewHas('totalEarned', 100.0)
            ->assertSee('1 Aug – 31 Aug 2026');
        $this->admin()->get('/admin/payroll?month=2026-10')->assertViewHas('totalEarned', 400.0);
        $this->admin()->get('/admin/payroll?month=2026-02')->assertSee('1 Feb – 28 Feb 2026');
    }

    public function test_payroll_defaults_to_current_month_and_rejects_bad_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));

        $this->admin()->get('/admin/payroll')->assertOk()->assertViewHas('month', '2026-09');
        $this->admin()->get('/admin/payroll?month=garbage')->assertSessionHasErrors('month');
    }

    /**
     * A cleared <input type="month"> submits "", which ConvertEmptyStringsToNull
     * turns into null — "nullable" waves it through and Carbon then throws on it.
     */
    public function test_blank_month_falls_back_to_the_current_month_instead_of_erroring(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-09-05 10:00', 300, 1);

        // Whichever way the blank arrives, it means "this month".
        foreach (['', '%20', '+'] as $blank) {
            $this->admin()->get("/admin/payroll?month={$blank}")
                ->assertOk()
                ->assertViewHas('month', '2026-09')
                ->assertViewHas('totalEarned', 300.0);
        }

        // Junk is still rejected, just never with a 500.
        foreach (['garbage', 'null', '2026-13', '2026-00', '2026-1'] as $junk) {
            $this->admin()->get("/admin/payroll?month={$junk}")
                ->assertStatus(302)
                ->assertSessionHasErrors('month');
        }
    }

    public function test_recording_partial_then_full_payment(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-09-05 10:00', 300, 1);
        $this->completed($e->id, '2026-09-06 10:00', 200, 2);

        $pay = fn (float $amt, array $extra = []) => $this->admin()->post('/admin/payroll/payments', [
            'employee_id' => $e->id, 'period' => '2026-09', 'amount' => $amt, 'paid_on' => '2026-09-23',
        ] + $extra);

        $pay(200, ['note' => 'bKash'])->assertRedirect(route('admin.payroll', ['month' => '2026-09']))->assertSessionHas('ok');
        $this->assertSame('200.00', Payment::first()->amount);
        $this->assertSame('bKash', Payment::first()->note);

        $r = $this->admin()->get('/admin/payroll?month=2026-09');
        $r->assertViewHas('totalEarned', 500.0)->assertViewHas('totalPaid', 200.0)->assertViewHas('totalDue', 300.0);
        $r->assertSee('Partly paid')->assertSee('bKash');

        $pay(300);
        $r = $this->admin()->get('/admin/payroll?month=2026-09');
        $r->assertViewHas('totalDue', 0.0)->assertSee('Paid in full');
    }

    public function test_cannot_pay_more_than_is_due_for_the_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-09-05 10:00', 500, 1);

        $post = fn (float $amt, string $period = '2026-09') => $this->admin()->post('/admin/payroll/payments', [
            'employee_id' => $e->id, 'period' => $period, 'amount' => $amt, 'paid_on' => '2026-09-23',
        ]);

        $post(500.01)->assertSessionHasErrors('amount');
        $post(0)->assertSessionHasErrors('amount');
        $post(-5)->assertSessionHasErrors('amount');
        $post(100, '2026-08')->assertSessionHasErrors('amount');   // nothing earned in August
        $this->assertSame(0, Payment::count());

        $post(500)->assertSessionHasNoErrors();
        $post(1)->assertSessionHasErrors('amount');               // double-click / already settled
        $this->assertSame(1, Payment::count());
    }

    public function test_payment_date_cannot_be_in_the_future_and_period_must_be_valid(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-09-05 10:00', 500, 1);

        $this->admin()->post('/admin/payroll/payments', ['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 10, 'paid_on' => '2026-09-30'])
            ->assertSessionHasErrors('paid_on');
        $this->admin()->post('/admin/payroll/payments', ['employee_id' => $e->id, 'period' => '2026-13', 'amount' => 10, 'paid_on' => '2026-09-23'])
            ->assertSessionHasErrors('period');
        $this->admin()->post('/admin/payroll/payments', ['employee_id' => 999, 'period' => '2026-09', 'amount' => 10, 'paid_on' => '2026-09-23'])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Payment::count());
    }

    public function test_removing_a_payment_makes_the_amount_due_again(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-09-05 10:00', 500, 1);
        $p = Payment::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 500, 'paid_on' => '2026-09-20']);

        $this->admin()->get('/admin/payroll?month=2026-09')->assertViewHas('totalDue', 0.0);

        $this->admin()->delete("/admin/payments/{$p->id}")->assertRedirect(route('admin.payroll', ['month' => '2026-09']));
        $this->admin()->get('/admin/payroll?month=2026-09')->assertViewHas('totalDue', 500.0);
    }

    public function test_unpaid_amounts_carry_over_as_arrears_and_show_in_history(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-08-10 10:00', 400, 1);
        $this->completed($e->id, '2026-09-10 10:00', 100, 2);
        Payment::create(['employee_id' => $e->id, 'period' => '2026-08', 'amount' => 150, 'paid_on' => '2026-09-01']);

        $r = $this->admin()->get('/admin/payroll?month=2026-09');
        $r->assertViewHas('totalArrears', 250.0)->assertViewHas('totalDue', 100.0);
        $r->assertSee('Unpaid from earlier months');

        $history = $r->viewData('history');
        $this->assertSame(['September 2026', 'August 2026'], array_column($history, 'label'));
        $this->assertSame([100.0, 400.0], array_column($history, 'earned'));
        $this->assertSame([0.0, 150.0], array_column($history, 'paid'));
        $this->assertSame([100.0, 250.0], array_column($history, 'due'));
    }

    public function test_employees_are_paid_independently(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $a = $this->employee('alice');
        $b = $this->employee('bob');
        $this->completed($a->id, '2026-09-05 10:00', 100, 1);
        $this->completed($b->id, '2026-09-05 11:00', 300, 2);
        Payment::create(['employee_id' => $a->id, 'period' => '2026-09', 'amount' => 100, 'paid_on' => '2026-09-20']);

        $rows = $this->admin()->get('/admin/payroll?month=2026-09')->viewData('rows')->keyBy(fn ($r) => $r['employee']->username);
        $this->assertSame('paid', $rows['alice']['status']);
        $this->assertSame('unpaid', $rows['bob']['status']);
        $this->assertSame(300.0, $rows['bob']['due']);
    }

    public function test_only_admin_can_record_or_remove_payments(): void
    {
        $e = $this->employee();
        $this->completed($e->id, now()->toDateTimeString(), 500, 1);
        $p = Payment::create(['employee_id' => $e->id, 'period' => now()->format('Y-m'), 'amount' => 100, 'paid_on' => now()->toDateString()]);
        $payload = ['employee_id' => $e->id, 'period' => now()->format('Y-m'), 'amount' => 50, 'paid_on' => now()->toDateString()];

        $this->post('/admin/payroll/payments', $payload)->assertRedirect(route('login'));
        $this->delete("/admin/payments/{$p->id}")->assertRedirect(route('login'));

        $this->loginAs('emp');
        $this->post('/admin/payroll/payments', $payload)->assertRedirect(route('login'));
        $this->delete("/admin/payments/{$p->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Payment::count());
    }

    public function test_deleting_an_employee_removes_their_payments(): void
    {
        $e = $this->employee();
        Payment::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 100, 'paid_on' => '2026-09-01']);

        $this->admin()->delete("/admin/employees/{$e->id}");

        $this->assertSame(0, Payment::count());
    }

    public function test_admin_can_give_an_employee_a_bonus_with_a_note(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');

        $this->admin()->post('/admin/payroll/bonuses', [
            'employee_id' => $e->id, 'amount' => 500, 'note' => 'Best performer of September',
        ])->assertRedirect(route('admin.payroll', ['month' => '2026-09']))->assertSessionHas('ok');

        $b = Bonus::first();
        $this->assertSame($e->id, (int) $b->employee_id);
        $this->assertSame('2026-09', $b->period);
        $this->assertSame('500.00', $b->amount);
        $this->assertSame('Best performer of September', $b->note);

        $r = $this->admin()->get('/admin/payroll?month=2026-09');
        $r->assertViewHas('totalBonus', 500.0)
            ->assertSee('Best performer of September')
            ->assertSee('Give Alice a bonus')
            ->assertSee('Not paid yet');
    }

    /** A bonus raises what is owed; it is not a payment, so it still has to be paid out. */
    public function test_a_bonus_is_owed_and_can_be_paid_like_earnings(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-09-05 10:00', 500, 1);
        Bonus::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 100, 'note' => 'Great work']);

        // 500 earned + 100 bonus = 600 owed, on top of the two settled independently.
        $r = $this->admin()->get('/admin/payroll?month=2026-09');
        $r->assertViewHas('totalEarned', 500.0)
            ->assertViewHas('totalBonus', 100.0)
            ->assertViewHas('totalPaid', 0.0)
            ->assertViewHas('totalDue', 600.0);

        $pay = fn (float $amt) => $this->admin()->post('/admin/payroll/payments', [
            'employee_id' => $e->id, 'period' => '2026-09', 'amount' => $amt, 'paid_on' => '2026-09-23',
        ]);

        $pay(600.01)->assertSessionHasErrors('amount');   // the guard counts the bonus
        $pay(600)->assertSessionHasNoErrors();
        $this->admin()->get('/admin/payroll?month=2026-09')
            ->assertViewHas('totalDue', 0.0)
            ->assertSee('Paid in full');
    }

    public function test_a_bonus_needs_an_amount_above_zero_and_a_note(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');

        $post = fn (array $over = []) => $this->admin()->post('/admin/payroll/bonuses', array_merge([
            'employee_id' => $e->id, 'amount' => 500, 'note' => 'Great work',
        ], $over));

        $post(['note' => ''])->assertSessionHasErrors('note');
        $post(['note' => '   '])->assertSessionHasErrors('note');   // TrimStrings, then required
        $post(['note' => str_repeat('x', 256)])->assertSessionHasErrors('note');
        $post(['amount' => 0])->assertSessionHasErrors('amount');
        $post(['amount' => -50])->assertSessionHasErrors('amount');
        $post(['amount' => 'abc'])->assertSessionHasErrors('amount');
        $post(['employee_id' => 999])->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Bonus::count());

        $post(['note' => str_repeat('x', 255), 'amount' => '0.01'])->assertSessionHasNoErrors();
        $this->assertSame(1, Bonus::count());
    }

    public function test_a_bonus_always_lands_in_the_current_month(): void
    {
        $e = $this->employee('alice');

        $this->travelTo(Carbon::parse('2026-09-30 23:59:59'));
        $this->admin()->post('/admin/payroll/bonuses', ['employee_id' => $e->id, 'amount' => 100, 'note' => 'September']);
        $this->assertSame('2026-09', Bonus::orderBy('id')->first()->period);

        $this->travelTo(Carbon::parse('2026-10-01 00:00:01'));
        $this->admin()->post('/admin/payroll/bonuses', ['employee_id' => $e->id, 'amount' => 100, 'note' => 'October']);
        $this->assertSame('2026-10', Bonus::orderByDesc('id')->first()->period);

        // October only counts the October bonus; September's carries over as arrears.
        $this->admin()->get('/admin/payroll?month=2026-10')
            ->assertViewHas('totalBonus', 100.0)
            ->assertViewHas('totalArrears', 100.0);

        // A past month shows the bonuses it carries but cannot be given one, so the
        // form never quietly credits a month the admin is not looking at.
        $this->admin()->get('/admin/payroll?month=2026-10')
            ->assertSee('Give Alice a bonus')
            ->assertDontSee('not a past one');

        $this->admin()->get('/admin/payroll?month=2026-09')
            ->assertSee('Bonuses are given for the current month, not a past one.')
            ->assertSee('1 bonus for Alice was given back then.')
            ->assertDontSee('Give Alice a bonus');

        $this->admin()->get('/admin/payroll?month=2026-08')
            ->assertSee('Open October 2026')
            ->assertDontSee('Give Alice a bonus');
    }

    public function test_removing_a_bonus_reduces_what_is_owed(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $b = Bonus::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 100, 'note' => 'Oops']);

        $this->admin()->get('/admin/payroll?month=2026-09')->assertViewHas('totalDue', 100.0);

        $this->admin()->delete("/admin/bonuses/{$b->id}")
            ->assertRedirect(route('admin.payroll', ['month' => '2026-09']));

        $this->assertSame(0, Bonus::count());
        $this->admin()->get('/admin/payroll?month=2026-09')->assertViewHas('totalDue', 0.0);
    }

    /** Someone whose only earnings this month are a bonus is owed money, not "nothing earned". */
    public function test_a_bonus_alone_counts_as_owed(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        Bonus::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 100, 'note' => 'Great work']);

        $rows = $this->admin()->get('/admin/payroll?month=2026-09')->viewData('rows')->keyBy(fn ($r) => $r['employee']->username);
        $this->assertSame('unpaid', $rows['alice']['status']);
        $this->assertSame(100.0, $rows['alice']['due']);
        $this->assertSame(0.0, $rows['alice']['earned']);
    }

    public function test_only_admin_can_give_or_remove_bonuses(): void
    {
        $e = $this->employee();
        $b = Bonus::create(['employee_id' => $e->id, 'period' => now()->format('Y-m'), 'amount' => 100, 'note' => 'Great work']);
        $payload = ['employee_id' => $e->id, 'amount' => 50, 'note' => 'Great work'];

        $this->post('/admin/payroll/bonuses', $payload)->assertRedirect(route('login'));
        $this->delete("/admin/bonuses/{$b->id}")->assertRedirect(route('login'));

        $this->loginAs('emp');
        $this->post('/admin/payroll/bonuses', $payload)->assertRedirect(route('login'));
        $this->delete("/admin/bonuses/{$b->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Bonus::count());
    }

    public function test_deleting_an_employee_removes_their_bonuses(): void
    {
        $e = $this->employee();
        Bonus::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 100, 'note' => 'Great work']);

        $this->admin()->delete("/admin/employees/{$e->id}");

        $this->assertSame(0, Bonus::count());
    }

    public function test_a_bonus_shows_in_the_month_by_month_history(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $e = $this->employee('alice');
        $this->completed($e->id, '2026-08-10 10:00', 400, 1);
        $this->completed($e->id, '2026-09-10 10:00', 100, 2);
        Bonus::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 250, 'note' => 'Great work']);
        Payment::create(['employee_id' => $e->id, 'period' => '2026-09', 'amount' => 100, 'paid_on' => '2026-09-20']);

        $history = $this->admin()->get('/admin/payroll?month=2026-09')->viewData('history');
        $this->assertSame(['September 2026', 'August 2026'], array_column($history, 'label'));
        $this->assertSame([100.0, 400.0], array_column($history, 'earned'));
        $this->assertSame([250.0, 0.0], array_column($history, 'bonus'));
        $this->assertSame([100.0, 0.0], array_column($history, 'paid'));
        $this->assertSame([250.0, 400.0], array_column($history, 'due'));
    }

    public function test_money_format(): void
    {
        $this->assertSame('Tk 1,250', Money::tk(1250));
        $this->assertSame('Tk 25.50', Money::tk('25.5'));
        $this->assertSame('Tk 0', Money::tk(null));
    }
}

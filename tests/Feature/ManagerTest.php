<?php

namespace Tests\Feature;

use App\Livewire\Manager\Topics as ManagerTopics;
use App\Models\Admin;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Rate;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ManagerTest extends TestCase
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

    /** Mark a topic as added by $manager, which is what the strip authorises on. */
    private function claimedBy(Topic $topic, Manager $manager): Topic
    {
        $topic->forceFill([
            'added_by_label' => $manager->name,
            'added_by_key' => 'manager:'.$manager->id,
        ])->save();

        return $topic;
    }

    /**
     * $count filler topics, oldest first, all in one channel and outside the strip.
     * The prefix keeps each channel's titles distinguishable, so a leak is visible
     * in the markup rather than hidden behind a colliding name.
     */
    private function bulkTopics(int $count, int $channelId, string $prefix = 'Bulk'): void
    {
        $start = now()->subDays(30)->subMinutes($count);

        Topic::insert(array_map(fn ($i) => [
            'channel_id' => $channelId, 'title' => "$prefix topic $i", 'category' => 'Other',
            'link' => '', 'sort_order' => $i,
            'created_at' => $start->copy()->addMinutes($i), 'updated_at' => $start->copy()->addMinutes($i),
        ], range(1, $count)));
    }

    private function employee(string $username = 'emp', string $password = 'pw'): Employee
    {
        return Employee::create(['name' => ucfirst($username), 'username' => $username, 'password' => $password]);
    }

    private function manager(string $username = 'mgr', string $password = 'pw', array $extra = []): Manager
    {
        $m = Manager::create(['name' => ucfirst($username), 'username' => $username, 'password' => $password] + $extra);

        return $m;
    }

    private function admin(): static
    {
        // The admin is a real row now, seeded from the environment by the
        // create_admins_table migration.
        return $this->actingAs(Admin::first(), 'admin');
    }

    private function channelsFor(Manager $m, Channel ...$channels): Manager
    {
        $m->channels()->sync(array_map(fn ($c) => $c->id, $channels));

        return $m;
    }

    private function loginAsManager(string $username, string $password = 'pw'): void
    {
        $this->post('/login', ['username' => $username, 'password' => $password]);
    }

    // ------------------------------------------------------------- admin CRUD

    public function test_admin_can_create_update_and_delete_managers_with_channels(): void
    {
        $c1 = $this->channel();
        $c2 = $this->channel('linux');

        $this->admin()->post('/admin/managers', ['name' => 'Karim', 'username' => 'karim', 'password' => 'secret', 'channels' => [$c1->id, $c2->id]])
            ->assertSessionHas('ok');
        $m = Manager::where('username', 'karim')->firstOrFail();
        $this->assertNotSame('secret', $m->password);
        $this->assertSame([$c1->id, $c2->id], $m->channels()->pluck('channels.id')->map(fn ($v) => (int) $v)->all());

        $hash = $m->password;
        $this->admin()->put("/admin/managers/{$m->id}", [
            'name' => 'Karim K', 'username' => 'karim', 'password' => '', 'is_active' => '0', 'channels' => [$c1->id],
        ])->assertSessionHas('ok');
        $m->refresh();
        $this->assertSame('Karim K', $m->name);
        $this->assertSame($hash, $m->password);
        $this->assertFalse($m->is_active);
        $this->assertSame([(int) $c1->id], $m->channels()->pluck('channels.id')->map(fn ($v) => (int) $v)->all());

        $this->admin()->delete("/admin/managers/{$m->id}");
        $this->assertNull(Manager::find($m->id));
        $this->assertSame(0, $m->channels()->count());
    }

    public function test_manager_username_cannot_clash_with_admin_or_employee(): void
    {
        $this->employee('rahim');
        $this->admin()->get('/admin/managers')->assertOk();

        $this->admin()->post('/admin/managers', ['name' => 'A', 'username' => 'rahim', 'password' => 'x'])
            ->assertSessionHasErrors('username');
        $this->admin()->post('/admin/managers', ['name' => 'A', 'username' => 'admin', 'password' => 'x'])
            ->assertSessionHasErrors('username');
        $this->assertSame(0, Manager::count());
    }

    public function test_employee_cannot_take_a_manager_username(): void
    {
        $this->manager('boss');

        $this->admin()->post('/admin/employees', ['name' => 'X', 'username' => 'boss', 'password' => 'pw'])
            ->assertSessionHasErrors('username');
        $this->assertSame(0, Employee::count());
    }

    /**
     * The login ladder compares usernames case-insensitively, so the collision
     * checks have to as well — otherwise "Rahim" and "RAHIM" are two accounts
     * where only one can ever be reached.
     */
    public function test_username_collisions_are_case_insensitive(): void
    {
        $this->manager('Rahim');

        $this->admin()->post('/admin/employees', ['name' => 'R', 'username' => 'RAHIM', 'password' => 'pw'])
            ->assertSessionHasErrors('username');
        $this->assertSame(0, Employee::count());

        $this->admin()->post('/admin/managers', ['name' => 'A', 'username' => 'ADMIN', 'password' => 'pw'])
            ->assertSessionHasErrors('username');
        $this->assertSame(1, Manager::count());
    }

    public function test_manager_cannot_be_renamed_to_the_admin_username_in_any_case(): void
    {
        $m = $this->manager('mgr');

        $this->admin()->put("/admin/managers/{$m->id}", ['name' => 'X', 'username' => 'AdMiN'])
            ->assertSessionHasErrors('username');

        $this->assertSame('mgr', $m->fresh()->username);
    }

    public function test_only_admin_can_manage_managers(): void
    {
        $this->get('/admin/managers')->assertRedirect(route('login'));
        $this->post('/admin/managers', ['name' => 'X', 'username' => 'x', 'password' => 'pw'])->assertRedirect(route('login'));

        $emp = $this->employee();
        // "emp" is an employee, so log in as the employee
        $this->post('/login', ['username' => 'emp', 'password' => 'pw'])->assertRedirect(route('employee.dashboard'));
        $this->get('/admin/managers')->assertRedirect(route('login'));
        $this->post('/admin/managers', ['name' => 'X', 'username' => 'x', 'password' => 'pw'])->assertRedirect(route('login'));

        $this->assertSame(0, Manager::count());
        $this->assertSame(1, Employee::count());
    }

    // --------------------------------------------------------------- login

    public function test_manager_login_routes_to_manager_portal_and_guards_others(): void
    {
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);

        $this->post('/login', ['username' => 'BAD', 'password' => 'x'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'boss', 'password' => 'wrong'])->assertSessionHasErrors('username');

        $this->post('/login', ['username' => 'boss', 'password' => 'pw'])->assertRedirect(route('manager.dashboard'));
        $this->get('/manager')->assertOk()->assertSee('Boss');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/employee')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('manager.dashboard'));

        $this->post('/logout')->assertRedirect(route('home'));
        $this->get('/manager')->assertRedirect(route('login'));
    }

    public function test_inactive_manager_cannot_log_in(): void
    {
        $this->manager('off', 'pw', ['is_active' => false]);

        $this->post('/login', ['username' => 'off', 'password' => 'pw'])->assertSessionHasErrors('username');
    }

    public function test_deactivated_manager_is_signed_out_mid_session(): void
    {
        $m = $this->manager('boss');
        $this->loginAsManager('boss');
        $this->get('/manager')->assertOk();

        $m->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->get('/manager')->assertRedirect(route('login'));
    }

    /**
     * The route middleware that signs out a deactivated manager only runs on a
     * full page load. Every Livewire action after that reaches the shared
     * /livewire/update endpoint instead, which carries none of it — so the
     * component itself has to re-check on every single action, not just mount().
     */
    public function test_deactivating_a_manager_mid_session_stops_the_livewire_component(): void
    {
        $mine = $this->channel('windows');
        $m = $this->manager('boss');
        $this->channelsFor($m, $mine);
        $t = $this->topic(['channel_id' => $mine->id]);

        $this->loginAsManager('boss');

        $component = Livewire::test(ManagerTopics::class);

        $m->update(['is_active' => false]);
        Auth::forgetGuards();

        $component->call('delete', $t->id)->assertForbidden();

        $this->assertNotNull(Topic::find($t->id), 'The call must not have reached the delete.');
    }

    // ------------------------------------------------------------- scoping

    public function test_manager_only_sees_and_manages_their_own_channels(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);

        $mineT = $this->topic(['channel_id' => $mine->id, 'title' => 'Mine']);
        $theirT = $this->topic(['channel_id' => $other->id, 'title' => 'Not mine']);
        $this->loginAsManager('boss');

        $this->get('/manager/topics')->assertOk()->assertSee('Mine')->assertDontSee('Not mine');

        $emp = $this->employee('rahim');

        // adding into an unassigned channel is rejected
        $this->post('/manager/topics', ['channel_id' => $other->id, 'title' => 'Sneaky'])
            ->assertSessionHasErrors('channel_id');
        // adding into an assigned channel works and can be assigned
        $this->post('/manager/topics', ['channel_id' => $mine->id, 'title' => 'Fresh', 'link' => 'https://example.com'])
            ->assertSessionHas('ok');
        $fresh = Topic::where('title', 'Fresh')->firstOrFail();
        $this->assertSame('Other', $fresh->category);
        $this->assertSame(10, $fresh->sort_order);

        // topics outside the manager's channels are unreachable
        $this->put("/manager/topics/{$theirT->id}", ['title' => 'Hax'])->assertForbidden();
        $this->delete("/manager/topics/{$theirT->id}")->assertForbidden();
        $this->put("/manager/topics/{$theirT->id}/assign", ['employee_id' => $emp->id])->assertForbidden();

        // assign within own channel works
        $this->put("/manager/topics/{$fresh->id}/assign", ['employee_id' => $emp->id])->assertSessionHas('ok');
        $this->assertSame($emp->id, $fresh->fresh()->assigned_to);

        // unassign works too
        $this->put("/manager/topics/{$fresh->id}/assign", ['employee_id' => 0])->assertSessionHas('ok');
        $this->assertNull($fresh->fresh()->assigned_to);
    }

    public function test_manager_can_assign_a_topic_while_adding_it(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);
        $emp = $this->employee('rahim');

        $this->loginAsManager('boss');

        $this->post('/manager/topics', [
            'channel_id' => $mine->id, 'title' => 'Assigned on create', 'employee_id' => $emp->id,
        ])->assertSessionHas('ok');

        $t = Topic::where('title', 'Assigned on create')->firstOrFail();
        $this->assertSame($emp->id, $t->assigned_to);
        $this->assertNull($t->added_by, 'A manager-assigned topic must not count as employee-added.');

        // adding without an employee leaves it unassigned
        $this->post('/manager/topics', ['channel_id' => $mine->id, 'title' => 'Unowned', 'employee_id' => ''])
            ->assertSessionHas('ok');
        $this->assertNull(Topic::where('title', 'Unowned')->firstOrFail()->assigned_to);

        // an unknown employee is rejected
        $this->post('/manager/topics', ['channel_id' => $mine->id, 'title' => 'Ghost', 'employee_id' => 999])
            ->assertSessionHasErrors('employee_id');
        $this->assertNull(Topic::where('title', 'Ghost')->first());

        // channel scoping still holds with an assignee attached
        $this->post('/manager/topics', [
            'channel_id' => $other->id, 'title' => 'Sneaky', 'employee_id' => $emp->id,
        ])->assertSessionHasErrors('channel_id');
        $this->assertNull(Topic::where('title', 'Sneaky')->first());
    }

    public function test_manager_recently_added_respects_their_channels_and_the_channel_filter(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);

        $mineT = $this->topic(['channel_id' => $mine->id, 'title' => 'Mine just added']);
        $oldT = $this->topic(['channel_id' => $mine->id, 'title' => 'Mine ages ago']);
        $theirT = $this->topic(['channel_id' => $other->id, 'title' => 'Theirs just added']);

        $oldT->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->loginAsManager('boss');

        $this->get('/manager/topics')->assertOk();

        $lw = Livewire::test(ManagerTopics::class, ['panel' => 'add']);
        $this->assertSame(
            ['Mine just added'],
            $lw->viewData('recent')['items']->pluck('title')->all(),
            'Only fresh topics in the manager\'s own channels.'
        );
        $lw->assertSee('Recently added')->assertSee('Mine just added')
            ->assertDontSee('Theirs just added', 'Another channel\'s topic must never leak in.');

        // The channel filter applies to the strip too. A channel the manager does
        // not own narrows to nothing rather than leaking or widening the scope.
        $this->assertSame(0, Livewire::test(ManagerTopics::class, ['filter' => (string) $other->id])
            ->viewData('recent')['total']);
    }

    /**
     * A manager who added a topic can correct and remove it from the Recently added
     * strip, and the strip cannot be used to reach a channel they do not own or a
     * topic another manager added.
     */
    public function test_manager_can_edit_and_delete_from_the_strip_but_only_what_they_added(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $boss = $this->manager('boss');
        $this->channelsFor($boss, $mine);
        $colleague = $this->manager('rosa');

        $mineT = $this->topic(['channel_id' => $mine->id, 'title' => 'Mine just added']);
        $claimed = $this->claimedBy($mineT, $boss);
        $theirT = $this->topic(['channel_id' => $mine->id, 'title' => 'Theirs just added']);
        $this->claimedBy($theirT, $colleague);
        $offChannelT = $this->topic(['channel_id' => $other->id, 'title' => 'Theirs in another channel']);
        $this->claimedBy($offChannelT, $colleague);

        $this->loginAsManager('boss');

        $lw = Livewire::test(ManagerTopics::class, ['panel' => 'add'])
            ->assertSeeHtml('data-recent-edit="'.$claimed->id.'"')
            ->assertSeeHtml('data-recent-delete="'.$claimed->id.'"')
            ->assertDontSeeHtml('data-recent-edit="'.$theirT->id.'"')
            ->assertDontSeeHtml('data-recent-delete="'.$theirT->id.'"')
            // and the other channel's topic is not in the strip at all
            ->assertDontSee('Theirs in another channel');

        $lw->call('editRecent', $claimed->id)
            ->assertSet('editingRecent', $claimed->id)
            ->assertSet('recentDraft.title', 'Mine just added')
            ->set('recentDraft.title', 'Renamed by the manager')
            ->call('saveRecentEdit')
            ->assertHasNoErrors()
            ->assertSet('editingRecent', null);

        $this->assertSame('Renamed by the manager', $claimed->fresh()->title);

        // the strip is also a delete button
        $lw->call('deleteRecent', $claimed->id)->assertHasNoErrors();
        $this->assertNull(Topic::find($claimed->id));

        // A colleague's topic is refused even when it sits in a channel this manager
        // owns, and so is a channel they were unhooked from. Each refusal is its own
        // request, as it would be from the browser.
        Livewire::test(ManagerTopics::class)->call('editRecent', $theirT->id)->assertForbidden();
        Livewire::test(ManagerTopics::class)->call('deleteRecent', $theirT->id)->assertForbidden();
        Livewire::test(ManagerTopics::class)->call('deleteRecent', $offChannelT->id)->assertForbidden();

        $this->assertSame(2, Topic::count(), 'Only the manager\'s own topic was removed.');
    }

    /**
     * The strip's own draft must not disturb the table's open row, since the page
     * can have one of each open at the same time.
     */
    public function test_the_strip_editor_does_not_disturb_the_table_editor(): void
    {
        $c = $this->channel('windows');
        $boss = $this->manager('boss');
        $this->channelsFor($boss, $c);

        $stripT = $this->claimedBy($this->topic(['channel_id' => $c->id, 'title' => 'From the strip']), $boss);
        $rowT = $this->claimedBy($this->topic(['channel_id' => $c->id, 'title' => 'From the table']), $boss);

        $this->loginAsManager('boss');

        $lw = Livewire::test(ManagerTopics::class)
            ->call('edit', $rowT->id)
            ->call('editRecent', $stripT->id)
            ->assertSet('editing', $rowT->id)
            ->assertSet('editingRecent', $stripT->id)
            ->set('draft.title', 'Table edit')
            ->call('saveEdit')
            ->assertHasNoErrors()
            // saving the table row leaves the strip's row open, as typed
            ->assertSet('editingRecent', $stripT->id)
            ->set('recentDraft.title', 'Strip edit')
            ->call('saveRecentEdit')
            ->assertHasNoErrors()
            ->assertSet('editingRecent', null);

        $this->assertSame('Table edit', $rowT->fresh()->title);
        $this->assertSame('Strip edit', $stripT->fresh()->title);
    }

    /**
     * The table only shows the newest hundred of each channel until the manager asks
     * for the rest, and the cut has to live inside their own channels: it may not
     * become a way to count or reveal another channel's topics. The cut is per
     * channel, so a quiet channel keeps all of its handful of topics even while a
     * busy one is being trimmed.
     */
    public function test_the_manager_table_cut_stays_inside_their_channels_and_filter(): void
    {
        $busy = $this->channel('windows');
        $quiet = $this->channel('linux');
        $notMine = $this->channel('macos');

        $this->channelsFor($this->manager('boss'), $busy, $quiet);

        $this->bulkTopics(Topic::PREVIEW_LIMIT + 10, $busy->id, 'Busy');
        $this->bulkTopics(3, $quiet->id, 'Quiet');
        $this->bulkTopics(4, $notMine->id, 'Theirs');

        $this->loginAsManager('boss');

        $lw = Livewire::test(ManagerTopics::class);
        $shown = Topic::PREVIEW_LIMIT + 3;

        $this->assertSame($shown, substr_count($lw->html(), 'data-topic-row='));
        $lw->assertSee('Showing the newest '.Topic::PREVIEW_LIMIT.' of each channel, '.$shown.' of '.(Topic::PREVIEW_LIMIT + 13).' topics in all')
            ->assertDontSee('Theirs topic 1', 'Another channel is never counted in the footer.');

        // The quiet channel is inside its own budget, so it keeps all three of its
        // topics rather than losing them to the busy channel's overspill.
        $lw->assertSee('Quiet topic 1')
            ->assertSee('Quiet topic 3')
            ->assertSee('newest '.Topic::PREVIEW_LIMIT.' of '.(Topic::PREVIEW_LIMIT + 10));

        // Show all stops at the manager's own channels, and no further.
        $lw->call('showAllTopics')->assertSet('showAll', true);
        $this->assertSame(Topic::PREVIEW_LIMIT + 13, substr_count($lw->html(), 'data-topic-row='));
        $lw->assertDontSee('Theirs topic 1');

        // A channel of their own with a handful of topics needs no footer at all.
        Livewire::test(ManagerTopics::class, ['filter' => (string) $quiet->id])
            ->assertSee('Quiet topic 1')
            ->assertSee('Quiet topic 3')
            ->assertDontSeeHtml('data-topic-cut');

        // And a channel of somebody else's still narrows to nothing rather than
        // leaking a count through the footer.
        $this->assertSame(0, Livewire::test(ManagerTopics::class, ['filter' => (string) $notMine->id])
            ->viewData('topicTotal'));
    }

    public function test_manager_cannot_add_a_topic_to_a_channel_they_do_not_own(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);

        $this->loginAsManager('boss');

        Livewire::test(ManagerTopics::class)
            ->set('channel_id', $other->id)
            ->set('title', 'Sneaky')
            ->call('store')
            ->assertHasErrors(['channel_id']);

        $this->assertDatabaseMissing('topics', ['title' => 'Sneaky']);
    }

    public function test_reassigning_a_completed_topic_reports_the_reason_instead_of_erroring(): void
    {
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);
        $a = $this->employee('a');
        $b = $this->employee('b');
        $t = $this->topic(['channel_id' => $c->id, 'assigned_to' => $a->id]);
        $t->markDone();

        $this->loginAsManager('boss');

        Livewire::test(ManagerTopics::class)
            ->call('assign', $t->id, $b->id)
            ->assertHasErrors('assign');

        $this->assertSame($a->id, $t->fresh()->assigned_to);
    }

    public function test_completed_topics_cannot_be_reassigned_by_manager(): void
    {
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);
        $a = $this->employee('a');
        $b = $this->employee('b');
        $t = $this->topic(['channel_id' => $c->id, 'assigned_to' => $a->id]);
        $t->markDone();

        $this->loginAsManager('boss');
        $this->put("/manager/topics/{$t->id}/assign", ['employee_id' => $b->id])
            ->assertSessionHasErrors('assign');
        $this->assertSame($a->id, $t->fresh()->assigned_to);
    }

    /**
     * The assignee picker only ever lists active employees, so accepting an
     * inactive one here would only be reachable by posting the id directly — but
     * it would hand the topic to someone who can no longer log in to complete it.
     */
    public function test_a_deactivated_employee_cannot_be_assigned_a_topic(): void
    {
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);
        $inactive = $this->employee('gone');
        $inactive->update(['is_active' => false]);
        $t = $this->topic(['channel_id' => $c->id]);

        $this->loginAsManager('boss');
        $this->put("/manager/topics/{$t->id}/assign", ['employee_id' => $inactive->id])
            ->assertSessionHas('ok');

        $this->assertNull($t->fresh()->assigned_to, 'A deactivated employee must not be assignable.');
    }

    public function test_manager_can_edit_and_delete_own_channel_topics(): void
    {
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);
        $t = $this->topic(['channel_id' => $c->id, 'title' => 'Old', 'category' => 'x']);
        $this->loginAsManager('boss');

        $this->put("/manager/topics/{$t->id}", ['title' => 'New', 'category' => 'Cat', 'link' => ''])
            ->assertSessionHas('ok');
        $this->assertSame('New', $t->fresh()->title);
        $this->assertSame('Cat', $t->fresh()->category);

        $this->delete("/manager/topics/{$t->id}");
        $this->assertNull(Topic::find($t->id));
    }

    // ------------------------------------------------------------- earnings

    public function test_manager_sees_earnings_for_their_channels_only(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);

        $e = $this->employee('rahim');
        Rate::create(['channel_id' => $mine->id, 'employee_id' => $e->id, 'amount' => 100]);
        Rate::create(['channel_id' => $other->id, 'employee_id' => $e->id, 'amount' => 999]);

        $myDone = $this->topic(['channel_id' => $mine->id, 'assigned_to' => $e->id]);
        $myDone->markDone();
        $otherDone = $this->topic(['channel_id' => $other->id, 'assigned_to' => $e->id]);
        $otherDone->markDone();
        $this->assertSame('100.00', $myDone->fresh()->earned_amount);
        $this->assertSame('999.00', $otherDone->fresh()->earned_amount);

        $this->loginAsManager('boss');
        $r = $this->get('/manager/earnings?month=2026-09')->assertOk();

        $r->assertViewHas('earned', 100.0)->assertViewHas('monthEarned', 100.0)->assertViewHas('done', 1);
        $r->assertSee('Tk 100')->assertDontSee('Tk 999');

        // one row for the employee, holding only the manager's channel
        $row = $r->viewData('rows')->first();
        $this->assertSame($e->id, $row['employee']->id);
        $this->assertSame(1, $row['done']);
        $this->assertSame(100.0, $row['earned']);
        $this->assertSame(100.0, $row['cells'][$mine->id]['earned']);
        $this->assertArrayNotHasKey($other->id, $row['cells']);
        $this->assertSame([$mine->id], $r->viewData('managerChannels')->pluck('id')->map(fn ($v) => (int) $v)->all());

        // the employee's name in the matrix links to their day/week/month/year
        // breakdown, which must count only the manager's own channel too.
        $r->assertSee(route('manager.employees.earnings', $e), false);

        $show = $this->get(route('manager.employees.earnings', $e))->assertOk()
            ->assertSee('Rahim')->assertSee('Tk 100')->assertDontSee('Tk 999');

        $this->assertSame(1, $show->viewData('summary')['all']['count'],
            'Only the completion in the manager\'s own channel must be counted.');
        $this->assertSame(100.0, $show->viewData('summary')['all']['amount']);
    }

    public function test_manager_earnings_respect_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);
        $e = $this->employee();

        $lastMonth = $this->topic(['channel_id' => $c->id, 'assigned_to' => $e->id,
            'is_done' => true, 'completed_by' => $e->id, 'earned_amount' => 50, 'completed_at' => '2026-08-10 10:00']);
        $thisMonth = $this->topic(['channel_id' => $c->id, 'assigned_to' => $e->id,
            'is_done' => true, 'completed_by' => $e->id, 'earned_amount' => 20, 'completed_at' => '2026-09-05 10:00']);

        $this->loginAsManager('boss');

        $this->get('/manager/earnings?month=2026-09')
            ->assertViewHas('monthEarned', 20.0)->assertViewHas('earned', 70.0)->assertViewHas('monthDone', 1);
        $this->get('/manager/earnings?month=2026-08')
            ->assertViewHas('monthEarned', 50.0)->assertViewHas('monthDone', 1);
        $this->get('/manager/earnings?month=garbage')->assertSessionHasErrors('month');
    }

    /** A cleared month input submits "", which used to reach Carbon and 500. */
    public function test_manager_earnings_fall_back_to_the_current_month_when_blank(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00'));
        $c = $this->channel();
        $this->channelsFor($this->manager('boss'), $c);
        $e = $this->employee();

        $this->topic(['channel_id' => $c->id, 'assigned_to' => $e->id,
            'is_done' => true, 'completed_by' => $e->id, 'earned_amount' => 20, 'completed_at' => '2026-09-05 10:00']);

        $this->loginAsManager('boss');

        $this->get('/manager/earnings?month=')
            ->assertOk()
            ->assertViewHas('month', '2026-09')
            ->assertViewHas('monthEarned', 20.0);
    }

    /** The search is client-side, so the test guards the wiring and the scoping. */
    public function test_manager_topic_search_is_wired_and_only_covers_their_channels(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);
        $this->topic(['title' => 'Install Ollama on Windows', 'category' => 'Local AI']);
        $this->topic(['channel_id' => $other->id, 'title' => 'Install Ollama on Ubuntu']);

        $this->loginAsManager('boss');

        $html = $this->get('/manager/topics')->assertOk()
            ->assertSee('data-topic-search', false)
            ->assertSee('js/topic-search.js', false)
            ->assertSee('data-search="Install Ollama on Windows Local AI Windows"', false)
            ->getContent();

        $this->assertStringNotContainsString('data-search="Install Ollama on Ubuntu', $html);
        $this->assertSame(1, substr_count($html, 'data-search='));
    }

    /**
     * The status/assignee/added filters work the same way as the admin page's, and
     * stay inside the manager's own channels — a manager cannot use the assignee
     * filter to discover who is working in a channel they do not own.
     */
    public function test_manager_status_and_assignee_filters_narrow_the_list(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);
        $alice = $this->employee('alice');

        $done = $this->topic(['channel_id' => $mine->id, 'title' => 'Done one', 'assigned_to' => $alice->id]);
        $done->toggleDone();
        $this->topic(['channel_id' => $mine->id, 'title' => 'Pending one', 'assigned_to' => $alice->id]);
        $this->topic(['channel_id' => $other->id, 'title' => 'Not mine', 'assigned_to' => $alice->id]);

        $this->loginAsManager('boss');

        $doneOnly = Livewire::test(ManagerTopics::class, ['statusFilter' => 'done']);
        $this->assertSame(['Done one'], $doneOnly->viewData('topics')->pluck('title')->all());

        $alicesOnly = Livewire::test(ManagerTopics::class, ['assigneeFilter' => (string) $alice->id]);
        $this->assertSame(
            ['Pending one', 'Done one'],
            $alicesOnly->viewData('topics')->pluck('title')->all(),
            'The assignee filter must stay inside the manager\'s own channels.'
        );
    }

    /**
     * The manager page has the same add/show split as the admin page — the Show
     * Topic page (the default) and the Add Topic page (?panel=add) — and the
     * chooser only ever offers the channels this manager owns: another channel's
     * name must not appear there either.
     */
    public function test_manager_topic_page_has_the_same_panels_over_their_own_channels(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);
        $this->topic(['title' => 'Mine']);

        $this->loginAsManager('boss');

        $show = $this->get('/manager/topics')->assertOk()
            ->assertDontSee('wire:submit="store"', false)
            ->getContent();

        $this->assertStringContainsString('<option value="'.$mine->id.'">', $show);
        $this->assertStringNotContainsString('<option value="'.$other->id.'">', $show);

        $this->get('/manager/topics?panel=add')->assertOk()
            ->assertSee('wire:submit="store"', false)
            ->assertDontSee('data-topic-search', false);
    }

    // ------------------------------------------------------------- rates

    /**
     * The dashboard's "+ Add topic" button used to link to the bare Topics page,
     * which defaults to the Show Topic list rather than the form.
     */
    public function test_the_manager_dashboard_add_topic_button_opens_the_add_topic_page(): void
    {
        $this->channelsFor($this->manager('boss', 'pw', ['is_active' => true]), $this->channel());
        $this->loginAsManager('boss');

        $this->get('/manager')->assertOk()
            ->assertSee(route('manager.topics', ['panel' => 'add']), false);
    }

    public function test_manager_can_set_pay_rates_for_their_own_channels_from_the_dashboard(): void
    {
        $mine = $this->channel('windows');
        $e = $this->employee('emp');
        $this->channelsFor($this->manager('boss', 'pw', ['is_active' => true]), $mine);

        $this->loginAsManager('boss');

        $this->get('/manager')->assertOk()
            ->assertSee('Pay rates for your channels')
            ->assertSee('name="channels['.$mine->id.']['.$e->id.']"', false);

        $this->put('/manager/rates', ['channels' => [$mine->id => [$e->id => '25.50']]])
            ->assertSessionHas('ok');

        $this->assertSame(25.5, (float) Rate::where('channel_id', $mine->id)->where('employee_id', $e->id)->value('amount'));

        // the saved rate shows up in the form
        $this->get('/manager')->assertSee('25.50');
    }

    public function test_manager_cannot_set_a_rate_for_a_channel_they_do_not_own(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $e = $this->employee('emp');
        $this->channelsFor($this->manager('boss', 'pw', ['is_active' => true]), $mine);

        $this->loginAsManager('boss');
        $this->put('/manager/rates', ['channels' => [$other->id => [$e->id => '999']]])
            ->assertSessionHasErrors('channels');

        $this->assertSame(0, Rate::where('channel_id', $other->id)->count());
    }

    /**
     * A disallowed channel anywhere in the submission fails the whole save, not
     * just the channel that triggered it — otherwise the channels validated
     * before the bad one would already be written despite the request as a whole
     * coming back as an error.
     */
    public function test_a_disallowed_channel_in_the_rates_form_rejects_the_whole_save(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $e = $this->employee('emp');
        $this->channelsFor($this->manager('boss', 'pw', ['is_active' => true]), $mine);

        $this->loginAsManager('boss');
        $this->put('/manager/rates', [
            'channels' => [$mine->id => [$e->id => '50'], $other->id => [$e->id => '999']],
        ])->assertSessionHasErrors('channels');

        $this->assertSame(0, Rate::where('channel_id', $mine->id)->count(),
            'The allowed channel must not be saved either, once the request as a whole failed.');
        $this->assertSame(0, Rate::where('channel_id', $other->id)->count());
    }

    public function test_manager_cannot_set_a_negative_rate(): void
    {
        $mine = $this->channel('windows');
        $e = $this->employee('emp');
        $this->channelsFor($this->manager('boss', 'pw', ['is_active' => true]), $mine);

        $this->loginAsManager('boss');
        $this->put('/manager/rates', ['channels' => [$mine->id => [$e->id => '-5']]])
            ->assertSessionHasErrors();

        $this->assertSame(0, Rate::count());
    }

    // ------------------------------------------------------------- account
    public function test_manager_can_change_their_own_username_and_password(): void
    {
        $m = $this->manager('boss', 'oldpass', ['is_active' => true]);
        $this->channelsFor($m, $this->channel());

        $this->actingAs($m, 'manager')->get('/manager/account')
            ->assertOk()
            ->assertSee('boss')
            ->assertSee('Current password');

        $this->actingAs($m, 'manager')->put('/manager/account', [
            'name' => 'Boss Rahim',
            'username' => 'boss2',
            'current_password' => 'oldpass',
            'password' => 'a-longer-secret',
            'password_confirmation' => 'a-longer-secret',
        ])->assertRedirect(route('manager.account'))->assertSessionHas('ok');

        $m->refresh();
        $this->assertSame('Boss Rahim', $m->name);
        $this->assertSame('boss2', $m->username);
        $this->assertTrue(Hash::check('a-longer-secret', $m->password));
    }

    public function test_manager_cannot_change_their_details_without_the_current_password(): void
    {
        $m = $this->manager('boss', 'oldpass', ['is_active' => true]);

        $this->actingAs($m, 'manager')->put('/manager/account', [
            'name' => 'X', 'username' => 'boss2', 'current_password' => 'not-it',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame('boss', $m->fresh()->username);
    }

    public function test_manager_cannot_take_an_employee_or_admin_username_from_the_account_page(): void
    {
        $m = $this->manager('boss', 'oldpass', ['is_active' => true]);
        $this->employee('emp');

        $this->actingAs($m, 'manager')->put('/manager/account', [
            'name' => 'X', 'username' => 'EMP', 'current_password' => 'oldpass',
        ])->assertSessionHasErrors('username');

        $this->actingAs($m, 'manager')->put('/manager/account', [
            'name' => 'X', 'username' => 'admin', 'current_password' => 'oldpass',
        ])->assertSessionHasErrors('username');

        $this->assertSame('boss', $m->fresh()->username);
    }

    /**
     * UniqueLoginUsername only sees the live rows in admins/managers/employees, so
     * it stops catching the reserved name the moment the admin's own row is
     * renamed away from it. NotAdminUsername is what still reserves the name in
     * config('tracker.admin_username') regardless of what the admin row is
     * actually called right now.
     */
    public function test_manager_cannot_take_the_reserved_admin_username_even_once_the_admin_row_is_renamed(): void
    {
        Admin::first()->update(['username' => 'not-the-reserved-name']);
        $m = $this->manager('boss', 'oldpass', ['is_active' => true]);

        $this->actingAs($m, 'manager')->put('/manager/account', [
            'name' => 'X', 'username' => config('tracker.admin_username'), 'current_password' => 'oldpass',
        ])->assertSessionHasErrors('username');

        $this->assertSame('boss', $m->fresh()->username);
    }

    /** Leaving the password boxes empty keeps the old password. */
    public function test_manager_can_change_their_username_without_touching_the_password(): void
    {
        $m = $this->manager('boss', 'oldpass', ['is_active' => true]);

        $this->actingAs($m, 'manager')->put('/manager/account', [
            'name' => 'Boss', 'username' => 'boss2', 'current_password' => 'oldpass',
        ])->assertSessionHas('ok');

        $this->assertSame('boss2', $m->fresh()->username);
        $this->assertTrue(Hash::check('oldpass', $m->fresh()->password));
    }

    public function test_employee_cannot_reach_the_manager_account_page(): void
    {
        $this->employee('emp');

        $this->post('/login', ['username' => 'emp', 'password' => 'pw']);
        $this->get('/manager/account')->assertRedirect(route('login'));
        $this->put('/manager/account', ['name' => 'X', 'username' => 'x', 'current_password' => 'pw'])
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------- tracker

    public function test_manager_sees_only_their_channels_in_the_tracker(): void
    {
        $mine = $this->channel('windows');
        $other = $this->channel('linux');
        $this->channelsFor($this->manager('boss'), $mine);

        $myTopic = $this->topic(['channel_id' => $mine->id, 'title' => 'Install Ollama on Windows', 'category' => 'Windows']);
        $otherTopic = $this->topic(['channel_id' => $other->id, 'title' => 'Install Ollama on Ubuntu', 'category' => 'Linux']);

        // public visitors still see everything
        $this->get('/tracker')->assertSee($myTopic->title)->assertSee($otherTopic->title);

        $this->loginAsManager('boss');
        $r = $this->get('/tracker')->assertOk()
            ->assertSee($myTopic->title)
            ->assertDontSee($otherTopic->title);
        $this->assertSame(1, $r->viewData('stats')['total']);
        $this->assertSame([$mine->id], $r->viewData('channels')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertInstanceOf(Manager::class, $r->viewData('manager'));
    }
}

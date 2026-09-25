<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Rate;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
        return $this->withSession(['tracker_admin' => true]);
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

        // only a channel the manager has appears
        $row = $r->viewData('rows')->first();
        $this->assertSame($mine->id, $row['channel']->id);
        $this->assertSame(1, $row['staff']->count());
        $this->assertSame(100.0, $row['staff']->first()['rate']);
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

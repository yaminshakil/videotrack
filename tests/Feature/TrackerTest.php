<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Topic;
use App\Services\YouTube;
use App\Support\Earnings;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
            'title'      => 'Install Ollama on Windows',
            'category'   => 'Windows',
        ]);
    }

    private function employee(string $username = 'emp', string $password = 'pw', array $extra = []): Employee
    {
        return Employee::create(['name' => ucfirst($username), 'username' => $username, 'password' => $password] + $extra);
    }

    private function admin(): static
    {
        return $this->withSession(['tracker_admin' => true]);
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

    public function test_deactivated_employee_is_signed_out_mid_session(): void
    {
        $e = $this->employee();
        $t = $this->topic(['assigned_to' => $e->id, 'video_url' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa']);
        $this->loginAs('emp');
        $this->get('/employee')->assertOk();

        $e->update(['is_active' => false]);
        \Illuminate\Support\Facades\Auth::forgetGuards(); // a real request starts with a fresh guard

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

        $this->getJson('/video-preview?url=' . urlencode('https://youtu.be/dQw4w9WgXcQ'))
            ->assertOk()->assertJson(['ok' => true, 'title' => 'Install Ollama Fast']);

        $this->getJson('/video-preview?url=' . urlencode('not a link'))
            ->assertJson(['ok' => false]);
    }

    public function test_video_preview_is_stateless_so_it_cannot_wipe_flash_messages(): void
    {
        $this->fakeYoutube();

        $response = $this->getJson('/video-preview?url=' . urlencode('https://youtu.be/dQw4w9WgXcQ'));

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

    public function test_money_format(): void
    {
        $this->assertSame('Tk 1,250', Money::tk(1250));
        $this->assertSame('Tk 25.50', Money::tk('25.5'));
        $this->assertSame('Tk 0', Money::tk(null));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page loads for the role that owns it.
 *
 * The portals are being converted to Livewire one page at a time, and a page can
 * easily end up referencing a variable or partial that only the old controller
 * used to provide. Those breakages only show up when the view is actually
 * rendered, so this walks the real routes rather than trusting unit tests.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_pages_load(): void
    {
        Channel::create(['name' => 'Windows', 'slug' => 'windows', 'sort_order' => 1]);

        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/tracker')->assertOk();
    }

    public function test_every_admin_page_loads(): void
    {
        $this->seedData();

        $this->actingAs(Admin::first(), 'admin');

        foreach ([
            '/admin',
            '/admin/topics',
            '/admin/employees',
            '/admin/managers',
            '/admin/earnings',
            '/admin/payroll',
            '/admin/account',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_every_manager_page_loads(): void
    {
        $this->seedData();

        $manager = Manager::create(['name' => 'Musa', 'username' => 'musa', 'password' => 'pw', 'is_active' => true]);
        $manager->channels()->attach(Channel::first()->id);

        $this->actingAs($manager, 'manager');

        foreach ([
            '/manager',
            '/manager/topics',
            '/manager/earnings',
            '/manager/account',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_every_employee_page_loads(): void
    {
        $employee = $this->seedData();

        $this->actingAs($employee, 'employee');

        foreach ([
            '/employee',
            '/employee/topics',
            '/employee/custom-topics',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** Enough rows that the pages have something to render, not just empty states. */
    private function seedData(): Employee
    {
        $channel = Channel::create(['name' => 'Windows', 'slug' => 'windows', 'sort_order' => 1]);
        $employee = Employee::create([
            'name' => 'Alice', 'username' => 'alice', 'password' => 'pw', 'is_active' => true,
        ]);

        Topic::create([
            'channel_id' => $channel->id,
            'title' => 'A topic',
            'category' => 'Setup',
            'link' => 'https://example.com',
            'assigned_to' => $employee->id,
            'added_by' => null,
            'added_by_label' => 'Seed',
            'sort_order' => 10,
        ]);

        return $employee;
    }
}

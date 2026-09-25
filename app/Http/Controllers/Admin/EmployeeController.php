<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Rate;
use App\Models\Topic;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index()
    {
        $employees = Employee::withCount('addedTopics')->orderBy('name')->orderBy('id')->get();
        $channels  = Channel::orderBy('sort_order')->orderBy('id')->get();

        // channel_id => employee_id => amount
        $rates = [];
        foreach (Rate::all() as $r) {
            $rates[$r->channel_id][$r->employee_id] = (float) $r->amount;
        }

        return view('admin.employees', [
            'employees' => $employees,
            'channels'  => $channels,
            'topics'    => Topic::ordered()->with('channel')->get(),
            'rates'     => $rates,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', 'unique:employees,username', Rule::notIn([config('tracker.admin_username')]), $this->notAManagerUsername()],
            'password' => ['required', 'string', 'min:6'],
        ], ['required' => 'Name, username and password are required.', 'password.min' => 'Password must be at least 6 characters.']);

        Employee::create($data);

        return back()->with('ok', 'Employee added.');
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', Rule::unique('employees', 'username')->ignore($employee->id), Rule::notIn([config('tracker.admin_username')]), $this->notAManagerUsername()],
            'password' => ['nullable', 'string', 'min:6'],
        ], ['required' => 'Name and username are required.', 'password.min' => 'Password must be at least 6 characters.']);

        $employee->name      = $data['name'];
        $employee->username  = $data['username'];
        $employee->is_active = $request->boolean('is_active');
        if (! empty($data['password'])) {
            $employee->password = $data['password'];
        }
        $employee->save();

        return back()->with('ok', 'Employee updated.');
    }

    public function destroy(Employee $employee)
    {
        // FK rules null out topics.assigned_to and cascade-delete rates.
        $employee->delete();

        return back()->with('ok', 'Employee deleted.');
    }

    public function saveRates(Request $request)
    {
        $request->validate(['channels' => ['array'], 'channels.*.*' => ['nullable', 'numeric', 'min:0']]);

        foreach ((array) $request->input('channels', []) as $channelId => $byEmployee) {
            foreach ((array) $byEmployee as $employeeId => $amount) {
                Rate::updateOrCreate(
                    ['channel_id' => (int) $channelId, 'employee_id' => (int) $employeeId],
                    ['amount' => (float) $amount]
                );
            }
        }

        return back()->with('ok', 'Rates saved.');
    }

    public function assign(Request $request, Topic $topic)
    {
        if ($topic->is_done) {
            return back()->withErrors(['assign' => 'Completed topics keep their assignee (their earnings are already recorded). Reopen the topic first to reassign it.']);
        }

        $employeeId = (int) $request->input('employee_id', 0);

        $topic->assigned_to = $employeeId > 0 && Employee::whereKey($employeeId)->exists() ? $employeeId : null;
        $topic->save();

        return back()->with('ok', 'Assignment updated.');
    }

    private function notAManagerUsername(): Closure
    {
        return function (string $attribute, mixed $value, $fail) {
            if (Manager::where('username', $value)->exists()) {
                $fail('That username is already used by a manager.');
            }
        };
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Topic;
use App\Rules\NotAdminUsername;
use App\Support\RateMatrix;
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
        $rates = RateMatrix::forChannels();

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
            'username' => ['required', 'string', 'max:60', 'unique:employees,username', new NotAdminUsername, $this->usernameNotUsedByAManager()],
            'password' => ['required', 'string', 'min:6'],
        ], ['required' => 'Name, username and password are required.', 'password.min' => 'Password must be at least 6 characters.']);

        Employee::create($data);

        return back()->with('ok', 'Employee added.');
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', Rule::unique('employees', 'username')->ignore($employee->id), new NotAdminUsername, $this->usernameNotUsedByAManager()],
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

        RateMatrix::save((array) $request->input('channels', []));

        return back()->with('ok', 'Rates saved.');
    }

    public function assign(Request $request, Topic $topic)
    {
        if ($topic->is_done) {
            return back()->withErrors(['assign' => 'Completed topics keep their assignee (their earnings are already recorded). Reopen the topic first to reassign it.']);
        }

        $employeeId = (int) $request->input('employee_id', 0);

        $topic->assigned_to = $employeeId > 0 && Employee::whereKey($employeeId)->where('is_active', true)->exists()
            ? $employeeId
            : null;
        $topic->save();

        return back()->with('ok', 'Assignment updated.');
    }

    /** Compared case-insensitively: the login ladder is, so a near-miss here
     *  would let one account shadow the other. */
    private function usernameNotUsedByAManager(): Closure
    {
        return function (string $attribute, mixed $value, $fail) {
            if (Manager::whereRaw('LOWER(username) = ?', [mb_strtolower((string) $value)])->exists()) {
                $fail('That username is already used by a manager.');
            }
        };
    }
}

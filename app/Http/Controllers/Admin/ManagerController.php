<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManagerController extends Controller
{
    public function index()
    {
        return view('admin.managers', [
            'managers' => Manager::with('channels')->orderBy('name')->orderBy('id')->get(),
            'channels' => Channel::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(), [
            'required' => 'Name, username and password are required.',
            'password.min' => 'Password must be at least 6 characters.',
        ]);

        $manager = Manager::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => $data['password'],
        ]);

        $manager->channels()->sync($this->channels($request));

        return back()->with('ok', 'Manager added.');
    }

    public function update(Request $request, Manager $manager)
    {
        $data = $request->validate($this->rules($manager->id), [
            'required' => 'Name and username are required.',
            'password.min' => 'Password must be at least 6 characters.',
        ]);

        $manager->name = $data['name'];
        $manager->username = $data['username'];
        $manager->is_active = $request->boolean('is_active');
        if (! empty($data['password'])) {
            $manager->password = $data['password'];
        }
        $manager->save();

        $manager->channels()->sync($this->channels($request));

        return back()->with('ok', 'Manager updated.');
    }

    public function destroy(Manager $manager)
    {
        $manager->channels()->detach();
        $manager->delete();

        return back()->with('ok', 'Manager deleted.');
    }

    private function rules(?int $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => [
                'required', 'string', 'max:60',
                Rule::unique('managers', 'username')->ignore($ignore),
                Rule::notIn([config('tracker.admin_username')]),
                function (string $attribute, mixed $value, $fail) {
                    if (Employee::where('username', $value)->exists()) {
                        $fail('That username is already used by an employee.');
                    }
                },
            ],
            'password' => $ignore === null
                ? ['required', 'string', 'min:6']
                : ['nullable', 'string', 'min:6'],
            'channels' => ['array'],
            'channels.*' => ['integer', 'exists:channels,id'],
        ];
    }

    private function channels(Request $request): array
    {
        return array_map('intval', (array) $request->input('channels', []));
    }
}

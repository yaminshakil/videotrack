<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Rules\NotAdminUsername;
use App\Rules\UniqueLoginUsername;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the admin and a manager change their own username and password, instead
 * of the admin having to edit a .env file and managers asking the admin to do it.
 *
 * Both roles share this because the rules are identical, and because the one
 * thing they must both do is prove they know the current password first.
 */
class AccountController extends Controller
{
    public function admin()
    {
        $admin = Auth::guard('admin')->user();

        if ($admin === null) {
            return $this->noAdminRow();
        }

        return view('account.admin', ['admin' => $admin]);
    }

    public function updateAdmin(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        if ($admin === null) {
            return $this->noAdminRow();
        }

        $data = $request->validate($this->rules('admin', 'admins', $admin->id), $this->messages());

        $admin->name = $data['name'];
        $admin->username = $data['username'];
        if (($data['password'] ?? null) !== null) {
            $admin->password = $data['password'];
        }
        $admin->save();

        $request->session()->regenerate();

        return redirect()->route('admin.account')->with('ok', 'Your details are updated.');
    }

    public function manager()
    {
        return view('account.manager', [
            'manager' => Auth::guard('manager')->user(),
        ]);
    }

    public function updateManager(Request $request)
    {
        $manager = Auth::guard('manager')->user();

        $data = $request->validate($this->rules('manager', 'managers', $manager->id), $this->messages());

        $manager->name = $data['name'];
        $manager->username = $data['username'];
        if (($data['password'] ?? null) !== null) {
            $manager->password = $data['password'];
        }
        $manager->save();

        $request->session()->regenerate();

        return redirect()->route('manager.account')->with('ok', 'Your details are updated.');
    }

    private function rules(string $guard, string $table, int $id): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // NotAdminUsername only applies to a manager renaming themselves: the
            // admin editing their own row would otherwise be rejected for already
            // holding the very username the rule reserves for them.
            'username' => array_filter([
                'required', 'string', 'min:3', 'max:60',
                new UniqueLoginUsername($table, $id),
                $guard === 'admin' ? null : new NotAdminUsername,
            ]),
            // Proving the current password stops a borrowed session from taking
            // the account over, and a blank new password keeps the old one.
            'current_password' => ['required', "current_password:{$guard}"],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    private function messages(): array
    {
        return [
            'current_password.current_password' => 'That is not your current password.',
            'password.min' => 'Your new password must be at least 8 characters.',
            'password.confirmed' => 'The two new passwords do not match.',
        ];
    }

    /**
     * Before the admins table exists the admin is authenticated by a session flag
     * rather than a guard user, so there is no row to edit. Send them through the
     * login form with an explanation, rather than showing a bare 403 on a page
     * they are otherwise allowed to be on.
     */
    private function noAdminRow(): Response
    {
        if (! Admin::tableExists()) {
            return redirect()->route('login')->withErrors([
                'account' => 'The admin account table has not been created yet, so there is no account to edit. Run "php artisan migrate", then sign in again.',
            ]);
        }

        // Past the migration the guard is the only way in, and the 'admin'
        // middleware should have stopped this request long before here.
        abort(403);
    }
}

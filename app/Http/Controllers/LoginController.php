<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Single login page for the admin, managers and employees. */
class LoginController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::guard('admin')->check() || $this->legacyAdminSession($request)) {
            return redirect()->route('admin.dashboard');
        }
        if (Auth::guard('employee')->check()) {
            return redirect()->route('employee.dashboard');
        }
        if (Auth::guard('manager')->check()) {
            return redirect()->route('manager.dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($this->attemptAdmin($request, $credentials)) {
            return redirect()->route('admin.dashboard');
        }

        if (Auth::guard('employee')->attempt($credentials + ['is_active' => true])) {
            $this->keepOnly($request, 'employee');
            $request->session()->regenerate();

            return redirect()->route('employee.dashboard');
        }

        if (Auth::guard('manager')->attempt($credentials + ['is_active' => true])) {
            $this->keepOnly($request, 'manager');
            $request->session()->regenerate();

            return redirect()->route('manager.dashboard');
        }

        return back()->withInput($request->only('username'))
            ->withErrors(['username' => 'Invalid username or password.']);
    }

    /**
     * A browser holds exactly one identity. Without this, logging in as an admin
     * and then as an employee in the same session would leave the employee with
     * the admin flag still set, and every /admin route open to them.
     */
    private function keepOnly(Request $request, string $authenticated): void
    {
        if ($authenticated !== 'employee') {
            Auth::guard('employee')->logout();
        }

        if ($authenticated !== 'manager') {
            Auth::guard('manager')->logout();
        }

        if ($authenticated !== 'admin') {
            Auth::guard('admin')->logout();
            $request->session()->forget('tracker_admin');
        }
    }

    /**
     * The admin is a row in `admins` now, so the login ladder reaches the admin
     * guard first and lets the database own the credentials.
     */
    private function attemptAdmin(Request $request, array $credentials): bool
    {
        if (Admin::tableExists()) {
            // A Closure credential makes the provider filter case-insensitively
            // itself, so "Admin" still signs in on a case-sensitive database.
            if (! Auth::guard('admin')->attempt([
                'username' => fn ($query) => $query->whereRaw('lower(username) = ?', [mb_strtolower(trim($credentials['username']))]),
                'password' => $credentials['password'],
            ])) {
                return false;
            }

            $this->keepOnly($request, 'admin');
            $request->session()->regenerate();

            return true;
        }

        // Deploying before migrating: fall back to the environment pair so the
        // admin is not locked out, and flag the session for EnsureAdmin.
        if (! $this->configAdminMatches($credentials)) {
            return false;
        }

        $this->keepOnly($request, 'admin');
        $request->session()->regenerate();
        $request->session()->put('tracker_admin', true);

        return true;
    }

    /** No configured ADMIN_PASSWORD means nobody can be the admin, not everybody. */
    private function configAdminMatches(array $credentials): bool
    {
        $admin = trim((string) config('tracker.admin_username'));
        $expected = (string) config('tracker.admin_password');

        return $admin !== ''
            && $expected !== ''
            && strcasecmp($credentials['username'], $admin) === 0
            && hash_equals($expected, $credentials['password']);
    }

    /**
     * The pre-migration session flag. Once the admins table exists this returns
     * false no matter what the session says, so a flag written by an older
     * release cannot keep granting admin access.
     */
    private function legacyAdminSession(Request $request): bool
    {
        return ! Admin::tableExists() && $request->session()->get('tracker_admin') === true;
    }

    public function logout(Request $request)
    {
        Auth::guard('employee')->logout();
        Auth::guard('manager')->logout();
        Auth::guard('admin')->logout();
        $request->session()->forget('tracker_admin');
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}

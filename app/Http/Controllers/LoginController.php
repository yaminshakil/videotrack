<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Single login page for both the admin and employees. */
class LoginController extends Controller
{
    public function show(Request $request)
    {
        if ($request->session()->get('tracker_admin')) {
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

        $isAdminName = strcasecmp($credentials['username'], (string) config('tracker.admin_username')) === 0;

        if ($isAdminName && hash_equals((string) config('tracker.admin_password'), $credentials['password'])) {
            $request->session()->regenerate();
            $request->session()->put('tracker_admin', true);

            return redirect()->route('admin.dashboard');
        }

        if (! $isAdminName && Auth::guard('employee')->attempt($credentials + ['is_active' => true])) {
            $request->session()->regenerate();

            return redirect()->route('employee.dashboard');
        }

        if (! $isAdminName && Auth::guard('manager')->attempt($credentials + ['is_active' => true])) {
            $request->session()->regenerate();

            return redirect()->route('manager.dashboard');
        }

        return back()->withInput($request->only('username'))
            ->withErrors(['username' => 'Invalid username or password.']);
    }

    public function logout(Request $request)
    {
        Auth::guard('employee')->logout();
        Auth::guard('manager')->logout();
        $request->session()->forget('tracker_admin');
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}

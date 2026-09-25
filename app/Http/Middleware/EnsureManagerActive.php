<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signs out a manager who was deactivated while still logged in. */
class EnsureManagerActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = Auth::guard('manager')->user();

        if ($manager && ! $manager->is_active) {
            Auth::guard('manager')->logout();
            $request->session()->regenerate();

            return redirect()->route('login')
                ->withErrors(['username' => 'Your account has been deactivated. Contact the admin.']);
        }

        return $next($request);
    }
}

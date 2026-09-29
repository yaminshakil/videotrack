<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAdmin($request)) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => 'Only the admin can change topic status here.'], 403);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }

    /**
     * The admin guard is the only thing that counts once the admins table
     * exists. The session flag is only honoured before the migration has run,
     * so a leftover flag from an older release cannot keep opening /admin.
     */
    private function isAdmin(Request $request): bool
    {
        if (Auth::guard('admin')->check()) {
            return true;
        }

        return ! Admin::tableExists() && $request->session()->get('tracker_admin') === true;
    }
}

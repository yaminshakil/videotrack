<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\Channel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardAdminCredentials();

        // The employee sidebar's Channels dropdown needs the same channel list
        // wherever it's included (dashboard, topics, tracker-as-employee), so
        // it's shared here instead of threaded through every controller.
        View::composer('employee._nav', function ($view) {
            $employee = Auth::guard('employee')->user();

            $view->with('navChannels', $employee
                ? Channel::whereHas('topics', fn ($q) => $q->where('assigned_to', $employee->id))
                    ->orderBy('sort_order')->get()
                : collect());
        });
    }

    /**
     * The admin authenticates against the `admins` table now. The environment
     * pair is only a pre-migration fallback, so leaving ADMIN_PASSWORD set
     * means the admin still has a plaintext copy of their password on disk and
     * may not have realised which one is actually in use.
     */
    private function guardAdminCredentials(): void
    {
        $password = (string) config('tracker.admin_password');

        if ($password !== '') {
            Log::critical('ADMIN_PASSWORD is still set. The admin now logs in against the admins table: run php artisan migrate, then remove ADMIN_USERNAME and ADMIN_PASSWORD from .env and restart the app.');

            if (in_array($password, ['admin', 'password', 'admin123', 'secret', '123456'], true) || strlen($password) < 12) {
                Log::critical('ADMIN_PASSWORD is weak (or a well-known default). Anyone who guesses it owns payroll, rates and employee passwords.');
            }

            return;
        }

        // The seeded row gets a random password, so the admin panel is locked
        // rather than open until somebody sets a real one. Only a problem if
        // the migration has not run yet, because then there is no row at all.
        if (! Admin::tableExists()) {
            Log::critical('No ADMIN_PASSWORD and no admins table: the admin panel is locked and /admin routes will reject every login. Run php artisan migrate.');
        }
    }
}

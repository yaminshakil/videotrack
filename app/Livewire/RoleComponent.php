<?php

namespace App\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/**
 * Base for the role-scoped full-page components.
 *
 * Route middleware is not enough on its own: a component's follow-up requests go
 * to the shared /livewire/update endpoint, which carries none of the per-page
 * middleware stack. mount() covers the first request and hydrate() every request
 * after it, so a component reached as an admin cannot then be driven by a manager
 * (or a signed-out browser) by calling its methods directly.
 *
 * The refusal matches what the route middleware used to do: everyone without the
 * right guard is sent back to the login page.
 */
abstract class RoleComponent extends Component
{
    /** The auth guard whose user is allowed on this page. */
    abstract protected function guard(): string;

    /**
     * The session flag that may stand in for this guard before its table exists,
     * or null when there is no such fallback. Mirrors the route middleware.
     */
    protected function legacySessionKey(): ?string
    {
        return null;
    }

    /**
     * Whether this guard's user table is there yet. Overridden by the roles whose
     * table is created by a later migration than the app itself.
     */
    protected function guardTableExists(): bool
    {
        return true;
    }

    public function mount()
    {
        if (! $this->authorized()) {
            return redirect()->route('login');
        }
    }

    public function hydrate(): void
    {
        if (! $this->authorized()) {
            throw new AuthorizationException;
        }
    }

    /**
     * The guard's own user always counts, provided they are still active — the
     * EnsureManagerActive/EnsureEmployeeActive route middleware only runs on the
     * page's first GET, and every Livewire action after that reaches the
     * package's shared /livewire/update endpoint instead, which carries none of
     * this page's route middleware. Re-checking is_active here is what stops a
     * manager deactivated mid-session from carrying on regardless: hydrate()
     * runs this on every single action, not just the first one.
     *
     * `!== false` rather than `=== true`: the admin guard's user has no
     * is_active column at all, and a missing attribute must not read as
     * deactivated.
     *
     * Failing that, a leftover session flag from a release that predates the
     * guard's table is honoured, exactly as the route middleware does, so an
     * un-migrated install still opens instead of locking everybody out.
     */
    protected function authorized(): bool
    {
        $user = $this->user();

        if ($user !== null) {
            return $user->is_active !== false;
        }

        $key = $this->legacySessionKey();

        return $key !== null
            && ! $this->guardTableExists()
            && request()->hasSession()
            && request()->session()->get($key) === true;
    }

    /** The signed-in user for this component's role, or null. */
    protected function user(): ?object
    {
        return auth()->guard($this->guard())->user();
    }
}

<?php

namespace App\Rules;

use App\Models\Admin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The admin username cannot live in an employees or managers row. `unique`
 * only sees the table it is given, and login compares with strcasecmp(), so a
 * case-insensitive comparison has to happen here too — otherwise an employee
 * called "Admin" can be created and then can never log in, because the login
 * ladder reaches the admin guard first.
 *
 * Both the database record and the pre-migration environment value are checked,
 * so the reservation survives a fresh migrate and an old .env alike.
 */
class NotAdminUsername implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        if (Admin::tableExists() && Admin::whereRaw('lower(username) = ?', [mb_strtolower($value)])->exists()) {
            $fail('That username is reserved for the admin.');

            return;
        }

        $admin = trim((string) config('tracker.admin_username'));

        if ($admin !== '' && strcasecmp($value, $admin) === 0) {
            $fail('That username is reserved for the admin.');
        }
    }
}

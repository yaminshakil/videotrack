<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A username has to be free in every table that can sign in, not just its own.
 *
 * The login page tries each guard in turn, so an employee called "admin" would
 * be sent to the admin portal, and a manager named like an employee would sign
 * them into the employee portal. `unique:employees` cannot see the other two
 * tables, and it would not catch a case difference either.
 */
class UniqueLoginUsername implements ValidationRule
{
    /** @var array<string, string> table => how to describe it to the user */
    private const TABLES = [
        'admins' => 'the admin account',
        'managers' => 'a manager',
        'employees' => 'an employee',
    ];

    /**
     * @param  string  $ownTable  the table the user is being saved to
     * @param  int|null  $ownId  their id, so keeping their own username is allowed
     */
    public function __construct(private string $ownTable, private ?int $ownId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $username = trim((string) $value);

        if ($username === '') {
            return;
        }

        foreach (self::TABLES as $table => $label) {
            if ($this->takenIn($table, $username)) {
                $fail("That username is already used by {$label}.");

                return;
            }
        }
    }

    private function takenIn(string $table, string $username): bool
    {
        // admins does not exist until the migration has run.
        if (! Schema::hasTable($table)) {
            return false;
        }

        return DB::table($table)
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->when($table === $this->ownTable && $this->ownId !== null, fn ($query) => $query->where('id', '!=', $this->ownId))
            ->exists();
    }
}

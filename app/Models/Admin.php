<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

/**
 * The tracker administrator. This used to be a username/password pair in the
 * environment, which meant the password sat in plaintext in .env and there was
 * nowhere to change it from the UI. It is a real record now, so the admin can
 * rotate their own credentials and the password is stored as a hash.
 */
class Admin extends Authenticatable
{
    protected $fillable = ['name', 'username', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected static ?bool $tableExists = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Whether the admins table has been created yet.
     *
     * The login page and EnsureAdmin both need to know this to stay usable in
     * the window between deploying this code and running the migration. Cached
     * for the process, which under php-fpm / artisan serve means the lifetime of
     * one request; a long-running worker would keep the answer until it restarts.
     */
    public static function tableExists(): bool
    {
        return static::$tableExists ??= Schema::hasTable('admins');
    }

    /** Drop the cached answer, e.g. after migrating inside a long-running process. */
    public static function forgetTableCache(): void
    {
        static::$tableExists = null;
    }

    /** The signed-in admin, for the topbar. Null while logging in or on the login page. */
    public static function current(): ?self
    {
        return auth('admin')->user();
    }
}

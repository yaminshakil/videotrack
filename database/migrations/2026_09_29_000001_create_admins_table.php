<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('username', 60)->unique();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });

        $this->seedFromEnvironment();
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }

    /**
     * Carry the existing environment credentials over so a live install does
     * not lock the admin out the moment it migrates.
     *
     * With no ADMIN_PASSWORD set the row still gets created, but with a random
     * password: failing closed means nobody gets in until the admin sets one,
     * which is the same behaviour the config-only login had.
     */
    private function seedFromEnvironment(): void
    {
        if (DB::table('admins')->exists()) {
            return;
        }

        $username = trim((string) config('tracker.admin_username'));

        if ($username === '') {
            $username = 'admin';
        }

        $password = (string) config('tracker.admin_password');

        DB::table('admins')->insert([
            'name' => 'Administrator',
            'username' => $username,
            'password' => Hash::make($password !== '' ? $password : Str::random(48)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

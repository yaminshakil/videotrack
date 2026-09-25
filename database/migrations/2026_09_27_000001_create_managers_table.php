<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('username', 60)->unique();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        // Which channels a manager can add topics to, assign from, and see earnings for.
        Schema::create('channel_manager', function (Blueprint $t) {
            $t->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manager_id')->constrained()->cascadeOnDelete();
            $t->primary(['channel_id', 'manager_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_manager');
        Schema::dropIfExists('managers');
    }
};

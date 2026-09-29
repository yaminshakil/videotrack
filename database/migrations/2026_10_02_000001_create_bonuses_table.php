<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonuses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('period', 7);            // the month the bonus counts towards, "YYYY-MM"
            $t->decimal('amount', 10, 2);
            $t->string('note', 255);            // why the bonus was given — the reason is the record
            $t->timestamps();

            $t->index(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonuses');
    }
};

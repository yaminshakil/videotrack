<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a topic a comparable owner, so the Recently added strip can offer Edit and
 * Delete to the person who added a topic and to nobody else.
 *
 * `added_by` is an employee foreign key and is deliberately NULL for anything an
 * admin or manager added, and `added_by_label` is a display name: it is not unique,
 * so two people called Alex would both be handed the other's topics. This column is
 * the stable identity the check actually needs — "guard:id", which spans all three
 * role tables without a foreign key that could not point at three of them at once.
 * The label stays as the human-readable half, exactly as the column above it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $t) {
            $t->string('added_by_key', 32)->nullable()->after('added_by_label')->index();
        });

        $this->backfillFromEmployees();
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $t) {
            $t->dropColumn('added_by_key');
        });
    }

    /**
     * Employee-added topics already know their author through `added_by`, so derive
     * the key from it. Staff-added topics predate this column and stay null: the
     * label beside it is all they ever recorded, and guessing an admin or manager id
     * from a name would hand one account another account's topics.
     */
    private function backfillFromEmployees(): void
    {
        DB::table('topics')
            ->whereNotNull('added_by')
            ->whereNull('added_by_key')
            ->orderBy('id')
            ->chunkById(500, function ($topics) {
                foreach ($topics as $topic) {
                    DB::table('topics')
                        ->where('id', $topic->id)
                        ->update(['added_by_key' => 'employee:'.$topic->added_by]);
                }
            });
    }
};

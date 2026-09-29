<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records who added a topic, for staff as well as employees.
 *
 * `added_by` cannot carry this: it is an employee foreign key that decides whether
 * a topic belongs on the employee's "My Topics" list or their "Custom Topics" one,
 * so it is deliberately NULL for anything an admin or manager added. This column
 * is a plain, denormalised name written at creation time, which also survives the
 * account being deleted later — the same reason `category` holds the adder's name
 * rather than a lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $t) {
            $t->string('added_by_label', 120)->nullable()->after('added_by');
        });

        $this->backfillFromEmployees();
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $t) {
            $t->dropColumn('added_by_label');
        });
    }

    /**
     * Employee-added topics already know their author through `added_by`, so
     * copy the name across. Staff-added topics predate this column and stay null:
     * there is no honest way to attribute them after the fact.
     */
    private function backfillFromEmployees(): void
    {
        DB::table('topics')
            ->whereNotNull('added_by')
            ->whereNull('added_by_label')
            ->orderBy('id')
            ->chunkById(200, function ($topics) {
                $names = Employee::whereIn('id', $topics->pluck('added_by')->unique())
                    ->pluck('name', 'id');

                foreach ($topics as $topic) {
                    if (isset($names[$topic->added_by])) {
                        DB::table('topics')
                            ->where('id', $topic->id)
                            ->update(['added_by_label' => $names[$topic->added_by]]);
                    }
                }
            });
    }
};

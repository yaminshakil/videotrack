<?php

use App\Models\Topic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-added topics used to be filed under the literal category
 * "Added by employee", and later under the adder's own name — both of which
 * showed up as the "Category" value on the admin/manager topic tables, where
 * it reads as wrong (an employee's name isn't a category). Categorize these
 * rows the same way every other topic is: from the title. The tracker page
 * gets its own per-employee section from added_by_label instead, so nothing
 * upstream depends on category holding a name any more.
 */
return new class extends Migration
{
    private const OLD_CATEGORY = 'Added by employee';

    public function up(): void
    {
        if (! Schema::hasTable('topics')) {
            return;
        }

        // added_by_label doesn't exist yet on a fresh install — this migration runs
        // before the one that adds it, so only a later re-run (this file changed
        // after add_added_by_label_to_topics had already run) has it to check.
        $hasLabelColumn = Schema::hasColumn('topics', 'added_by_label');

        // eachById, not each: the rows leave the result set as they are relabelled.
        Topic::query()
            ->whereNotNull('added_by')
            ->where(function ($q) use ($hasLabelColumn) {
                $q->where('category', self::OLD_CATEGORY);
                if ($hasLabelColumn) {
                    $q->orWhereColumn('category', 'added_by_label');
                }
            })
            ->eachById(function (Topic $topic) {
                $topic->forceFill(['category' => Topic::categoryFor($topic->title)])->saveQuietly();
            });
    }

    /**
     * A no-op on purpose. There is no marker left behind that distinguishes a row
     * this migration actually relabelled from a topic an employee has added
     * completely normally since — every one of those also has
     * category === categoryFor(title) by design, indistinguishable from the
     * rows up() touched. Guessing would revert real data on any row created
     * after this migration ran, in exchange for restoring a placeholder string
     * ('Added by employee') that was never useful in the first place.
     */
    public function down(): void {}
};

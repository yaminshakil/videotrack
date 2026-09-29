<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Employee;
use App\Models\Topic;
use Illuminate\Support\Collection;

/**
 * What each employee earned, per channel, for one month or for all time.
 *
 * Both the admin and the manager earnings pages need the same numbers, and the
 * only difference between them is which channels are in scope — so the
 * aggregation lives here instead of being written twice.
 */
class EarningsReport
{
    /**
     * @param  Collection<int, Channel>  $channels  the channels in scope
     * @param  string|null  $month  "YYYY-MM", or null for all time
     * @return array{
     *     rows: Collection<int, array{employee: Employee, cells: array<int, array{done: int, earned: float}>, done: int, earned: float}>,
     *     periodEarned: float, periodDone: int, allEarned: float, allDone: int,
     *     month: string|null, start: mixed, end: mixed, prevMonth: string|null, nextMonth: string|null
     */
    public static function build(Collection $channels, ?string $month = null): array
    {
        [$start, $end] = $month === null ? [null, null] : Month::range($month);

        $all = self::completedTopics($channels->modelKeys());
        $inPeriod = $month === null
            ? $all
            : $all->filter(fn (Topic $t) => $t->completed_at->between($start, $end))->values();

        return [
            'rows' => self::rows($channels, $inPeriod),
            'periodEarned' => (float) $inPeriod->sum('earned_amount'),
            'periodDone' => $inPeriod->count(),
            'allEarned' => (float) $all->sum('earned_amount'),
            'allDone' => $all->count(),
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'prevMonth' => $start?->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $start?->copy()->addMonthNoOverflow()->format('Y-m'),
        ];
    }

    /** Completed topics in these channels, with the employee who did them. */
    private static function completedTopics(array $channelIds): Collection
    {
        if ($channelIds === []) {
            return collect();
        }

        return Topic::whereIn('channel_id', $channelIds)
            ->where('is_done', true)
            ->whereNotNull('completed_by')
            ->whereNotNull('completed_at')
            ->get(['channel_id', 'completed_by', 'completed_at', 'earned_amount']);
    }

    /**
     * One row per employee, with a cell per channel. An employee who only has a
     * rate set and no completions still gets a row of zeroes, so a rate that has
     * not paid out yet is visible rather than looking like a missing employee.
     */
    private static function rows(Collection $channels, Collection $topics): Collection
    {
        $rates = RateMatrix::forChannels($channels);

        return Employee::orderBy('name')->orderBy('id')->get()
            ->map(function (Employee $employee) use ($channels, $topics, $rates) {
                $mine = $topics->where('completed_by', $employee->id);
                $cells = [];
                $done = 0;
                $earned = 0.0;

                foreach ($channels as $channel) {
                    $inChannel = $mine->where('channel_id', $channel->id);
                    $amount = (float) $inChannel->sum('earned_amount');

                    $cells[$channel->id] = ['done' => $inChannel->count(), 'earned' => $amount];
                    $done += $inChannel->count();
                    $earned += $amount;
                }

                return [
                    'employee' => $employee,
                    'cells' => $cells,
                    'done' => $done,
                    'earned' => $earned,
                    // isset, not a > 0 check on the looked-up value: an explicit Tk 0
                    // rate is still a rate someone set on purpose, and must not read
                    // the same as no rate having been set at all.
                    'hasRate' => $channels->contains(
                        fn (Channel $c) => isset($rates[$c->id][$employee->id])
                    ),
                ];
            })
            ->filter(fn (array $row) => $row['done'] > 0 || $row['earned'] > 0 || $row['hasRate'])
            ->sortByDesc('earned')
            ->values();
    }
}

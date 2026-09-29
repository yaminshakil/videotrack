<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\Employee;
use App\Models\Topic;

/**
 * The month-on-month headline numbers, in one place so the admin and manager
 * dashboards cannot start disagreeing about what "earned this month" means.
 */
class DashboardStats
{
    /** @return array<string, array{value: mixed, sub: string, trend?: string, icon: string, tone: string}> */
    public static function forAllChannels(): array
    {
        $thisStart = now()->startOfMonth();
        $thisEnd = now()->endOfMonth();
        $lastStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastEnd = now()->subMonthNoOverflow()->endOfMonth();

        $totalTopics = Topic::count();
        $doneTopics = Topic::where('is_done', true)->count();

        $earnedThis = (float) Topic::where('is_done', true)
            ->whereBetween('completed_at', [$thisStart, $thisEnd])->sum('earned_amount');
        $earnedLast = (float) Topic::where('is_done', true)
            ->whereBetween('completed_at', [$lastStart, $lastEnd])->sum('earned_amount');

        $doneThisMonth = Topic::where('is_done', true)
            ->whereBetween('completed_at', [$thisStart, $thisEnd])->count();
        $doneLastMonth = Topic::where('is_done', true)
            ->whereBetween('completed_at', [$lastStart, $lastEnd])->count();

        return [
            'employees' => [
                'value' => Employee::count(),
                'sub' => Employee::where('is_active', true)->count().' active',
                'icon' => '👥',
                'tone' => 'violet',
            ],
            'earned' => [
                'value' => $earnedThis,
                'sub' => self::deltaLabel($earnedThis, $earnedLast, 'from last month'),
                'trend' => self::deltaDirection($earnedThis, $earnedLast),
                'icon' => '💰',
                'tone' => 'blue',
            ],
            'completed' => [
                'value' => $doneThisMonth,
                'sub' => self::countDeltaLabel($doneThisMonth, $doneLastMonth),
                'trend' => self::deltaDirection($doneThisMonth, $doneLastMonth),
                'icon' => '✅',
                'tone' => 'teal',
            ],
            'rate' => [
                'value' => $totalTopics ? round($doneTopics / $totalTopics * 100) : 0,
                'sub' => ($totalTopics - $doneTopics).' topics remaining',
                'icon' => '📈',
                'tone' => 'amber',
            ],
        ];
    }

    public static function deltaDirection(float|int $now, float|int $prev): string
    {
        return $now > $prev ? 'up' : ($now < $prev ? 'down' : 'flat');
    }

    public static function deltaLabel(float $now, float $prev, string $suffix): string
    {
        if ($prev <= 0) {
            return $now > 0 ? 'new this month' : "no change {$suffix}";
        }

        $pct = round((($now - $prev) / $prev) * 100, 1);

        return ($pct >= 0 ? '+' : '').$pct."% {$suffix}";
    }

    public static function countDeltaLabel(int $now, int $prev): string
    {
        $diff = $now - $prev;

        return $diff === 0 ? 'same as last month' : ($diff > 0 ? '+' : '').$diff.' vs last month';
    }

    /** The most recently touched topics, for the dashboard activity list. */
    public static function recent(int $limit = 8)
    {
        return Topic::with(['channel', 'employee', 'completer'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public static function channels()
    {
        return Channel::orderBy('sort_order')->orderBy('id')->get();
    }
}

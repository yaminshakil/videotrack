<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Topic;
use Illuminate\Support\Collection;

/**
 * Per-channel headline numbers for a dashboard: how much work there is, how much
 * of it is finished, and what it has paid out.
 *
 * Both dashboards show this, differing only in which channels are passed in, so
 * the numbers cannot drift apart between the admin and manager views.
 */
class ChannelStats
{
    /**
     * @param  Collection<int, Channel>  $channels
     * @return Collection<int, array{channel: Channel, total: int, done: int, pending: int, unassigned: int, staff: int, earned: float, earnedMonth: float}>
     */
    public static function forChannels(Collection $channels): Collection
    {
        if ($channels->isEmpty()) {
            return collect();
        }

        $topics = Topic::whereIn('channel_id', $channels->modelKeys())
            ->get(['channel_id', 'assigned_to', 'is_done', 'earned_amount', 'completed_at']);

        $monthStart = now()->startOfMonth();

        return $channels->map(function (Channel $channel) use ($topics, $monthStart) {
            $mine = $topics->where('channel_id', $channel->id);
            $done = $mine->where('is_done', true);

            return [
                'channel' => $channel,
                'total' => $mine->count(),
                'done' => $done->count(),
                'pending' => $mine->count() - $done->count(),
                'unassigned' => $mine->whereNull('assigned_to')->count(),
                'staff' => $mine->pluck('assigned_to')->filter()->unique()->count(),
                'earned' => (float) $done->sum('earned_amount'),
                'earnedMonth' => (float) $done->filter(fn (Topic $t) => $t->completed_at?->gte($monthStart))->sum('earned_amount'),
            ];
        })->values();
    }
}

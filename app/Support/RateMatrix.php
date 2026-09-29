<?php

namespace App\Support;

use App\Models\Rate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The pay-rate matrix: what one employee earns for completing one topic in one
 * channel. It is edited from the admin and manager dashboards, so the lookup and
 * the write both live here rather than being re-implemented per page.
 */
class RateMatrix
{
    /**
     * Current rates as [channel_id][employee_id] => amount.
     *
     * @param  Collection|null  $channels  limit to these channels, or null for all of them
     * @return array<int, array<int, float>>
     */
    public static function forChannels(?Collection $channels = null): array
    {
        $query = Rate::query();

        if ($channels !== null) {
            $query->whereIn('channel_id', $channels->modelKeys());
        }

        $matrix = [];

        foreach ($query->get() as $rate) {
            $matrix[$rate->channel_id][$rate->employee_id] = (float) $rate->amount;
        }

        return $matrix;
    }

    /**
     * Write the submitted rates.
     *
     * A manager only owns some channels, and the form fields are named
     * channels[id][employee_id], so anybody could post a channel id they are not
     * allowed to set. Anything outside the allowed set is rejected rather than
     * quietly dropped, so a broken form is visible instead of a silent no-op.
     *
     * @param  array  $submitted  the raw `channels` input
     * @param  Collection|null  $allowedChannels  null means "any channel" (admin)
     * @return int how many rates were written
     */
    public static function save(array $submitted, ?Collection $allowedChannels = null): int
    {
        $allowedIds = $allowedChannels === null ? null : array_map('intval', $allowedChannels->modelKeys());

        // Checked in full before anything is written: rejecting a channel id
        // halfway through the loop would otherwise leave the channels before it
        // already saved, even though the request as a whole came back as an error.
        foreach (array_keys($submitted) as $channelId) {
            if ($allowedIds !== null && ! in_array((int) $channelId, $allowedIds, true)) {
                throw ValidationException::withMessages([
                    'channels' => 'You can only set pay rates for your own channels.',
                ]);
            }
        }

        $written = 0;

        foreach ($submitted as $channelId => $byEmployee) {
            foreach ((array) $byEmployee as $employeeId => $amount) {
                Rate::updateOrCreate(
                    ['channel_id' => (int) $channelId, 'employee_id' => (int) $employeeId],
                    ['amount' => (float) $amount]
                );

                $written++;
            }
        }

        return $written;
    }
}

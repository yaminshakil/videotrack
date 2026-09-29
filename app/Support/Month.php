<?php

namespace App\Support;

use Carbon\Carbon;

class Month
{
    /**
     * Turn a "YYYY-MM" query/input value into a month string that Carbon can
     * always parse. A missing, blank or malformed value falls back to the
     * current month, because the month pickers submit "" when cleared and
     * Carbon::createFromFormat() throws on that rather than returning null.
     */
    public static function resolve(mixed $requested): string
    {
        $value = is_scalar($requested) ? trim((string) $requested) : '';

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
            ? $value
            : now()->format('Y-m');
    }

    /** The calendar month a "YYYY-MM" string covers: 1st 00:00:00 to the last second. */
    public static function range(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}

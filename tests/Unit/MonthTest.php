<?php

namespace Tests\Unit;

use App\Support\Month;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthTest extends TestCase
{
    public function test_it_keeps_a_well_formed_month(): void
    {
        $this->assertSame('2026-09', Month::resolve('2026-09'));
        $this->assertSame('2026-01', Month::resolve('2026-01'));
        $this->assertSame('2026-12', Month::resolve('2026-12'));
    }

    public function test_it_falls_back_to_the_current_month_for_anything_unusable(): void
    {
        Carbon::setTestNow('2026-09-23 12:00');

        foreach ([null, '', '   ', 'garbage', 'null', '2026-13', '2026-00', '2026-1', '26-09', '2026-09-01', 0, [], new \stdClass] as $junk) {
            $this->assertSame('2026-09', Month::resolve($junk), 'Failed for: '.var_export($junk, true));
        }
    }

    public function test_range_covers_the_whole_calendar_month(): void
    {
        [$start, $end] = Month::range('2026-09');

        $this->assertSame('2026-09-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_range_handles_february_in_a_leap_year(): void
    {
        [$start, $end] = Month::range('2024-02');

        $this->assertSame('2024-02-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-29 23:59:59', $end->format('Y-m-d H:i:s'));
    }
}

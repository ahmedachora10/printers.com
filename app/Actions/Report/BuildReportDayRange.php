<?php

namespace App\Actions\Report;

use Illuminate\Support\Carbon;

/**
 * Every calendar day (Y-m-d) covered by a report's scope, used to zero-fill days
 * that carry no activity so a filtered range lists all of its days — including
 * the quiet ones, which read as 0.00 rather than vanishing from the table.
 *
 * Beyond MAX_DAYS the fill is skipped: a multi-year range would bloat the
 * payload with thousands of empty rows, so only days with real figures are kept.
 */
class BuildReportDayRange
{
    /** Widest span that still gets zero-filled (roughly one leap year). */
    public const MAX_DAYS = 366;

    /**
     * @param  array{from: ?Carbon, to: ?Carbon}  $scope
     * @return array<int, string>
     */
    public function handle(array $scope): array
    {
        $from = $scope['from'];
        $to = $scope['to'];

        if ($from === null || $to === null || $from->gt($to)) {
            return [];
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            return [];
        }

        $days = [];

        // now() is CarbonImmutable app-wide, so the cursor must be reassigned —
        // a bare $cursor->addDay() would loop forever.
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $days[] = $cursor->toDateString();
        }

        return $days;
    }
}

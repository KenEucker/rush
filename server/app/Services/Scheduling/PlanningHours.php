<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/** Exact elapsed seconds; overlapping paid sources count once, never alter pay records. */
class PlanningHours
{
    public function seconds(array $intervals, string $start, string $end): int
    {
        $lower = CarbonImmutable::parse($start)->timestamp;
        $upper = CarbonImmutable::parse($end)->timestamp;
        $ranges = [];
        foreach ($intervals as $interval) {
            if (! ($interval['paid'] ?? true)) {
                continue;
            }
            $from = max($lower, CarbonImmutable::parse($interval['starts_at'])->timestamp);
            $to = min($upper, CarbonImmutable::parse($interval['ends_at'])->timestamp);
            if ($from < $to) {
                $ranges[] = [$from, $to];
            }
        }
        sort($ranges);
        $seconds = 0;
        $cursor = $lower;
        foreach ($ranges as [$from, $to]) {
            $seconds += max(0, $to - max($from, $cursor));
            $cursor = max($cursor, $to);
        }

        return $seconds;
    }
}

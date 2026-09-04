<?php

namespace App\Reporting;

use App\Entity\Branch;
use App\Repository\AttendanceLogRepository;

/**
 * Home-dashboard "peak hours" chart (Owner analytics slice, §Phase 11).
 * Buckets every check-in in a trailing window by day-of-week × hour-of-day
 * so the Owner can see when the gym is actually busy.
 *
 * Live, not pre-aggregated: DAILY_METRIC_SNAPSHOT has no per-hour column,
 * and adding one was explicitly out of scope for this slice — a 30-day
 * window is a few thousand rows and the bucketing is cheap. Reads whatever
 * ATTENDANCE_LOG rows exist regardless of how the check-in was recorded
 * (front desk, self check-in, …) — there is no separate device data source.
 *
 * Day-of-week is 0 = Sunday … 6 = Saturday (PHP `date('w')`), matching the
 * frontend heatmap's column order.
 */
class PeakHoursAnalyzer
{
    public function __construct(
        private readonly AttendanceLogRepository $attendanceLogs,
    ) {
    }

    /**
     * @return array{
     *     grid: array<int, array{dayOfWeek: int, hour: int, count: int}>,
     *     maxCount: int,
     *     totalCheckins: int,
     *     windowDays: int
     * }
     */
    public function grid(int $windowDays, ?Branch $branch = null, ?\DateTimeImmutable $now = null): array
    {
        $windowDays = max(1, min(365, $windowDays));
        $now ??= new \DateTimeImmutable();
        $since = $now->modify(sprintf('-%d days', $windowDays))->setTime(0, 0);

        $counts = [];
        for ($day = 0; $day < 7; ++$day) {
            for ($hour = 0; $hour < 24; ++$hour) {
                $counts[$day][$hour] = 0;
            }
        }

        $total = 0;
        foreach ($this->attendanceLogs->checkInInstantsSince($since, $branch) as $instant) {
            $day = (int) $instant->format('w');
            $hour = (int) $instant->format('G');
            ++$counts[$day][$hour];
            ++$total;
        }

        $grid = [];
        $max = 0;
        for ($day = 0; $day < 7; ++$day) {
            for ($hour = 0; $hour < 24; ++$hour) {
                $count = $counts[$day][$hour];
                $max = max($max, $count);
                $grid[] = ['dayOfWeek' => $day, 'hour' => $hour, 'count' => $count];
            }
        }

        return [
            'grid' => $grid,
            'maxCount' => $max,
            'totalCheckins' => $total,
            'windowDays' => $windowDays,
        ];
    }
}

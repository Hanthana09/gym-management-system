<?php

namespace App\Reporting;

use App\Entity\Branch;

/**
 * Home-dashboard "at-risk members" sparkline (Owner analytics slice,
 * §Phase 11). One point per week for the trailing window, each the count
 * of members showing a retention risk signal as of that week's end.
 *
 * Live, not pre-aggregated: the underlying signals (RetentionAnalyzer) use
 * rolling 14-day lookback windows that shift every day, so a stored daily
 * number would answer a subtly different question. RetentionAnalyzer's
 * `asOf` is explicitly designed to be evaluated at a historical date (it
 * backs DailyMetricAggregator's backfill), so calling it once per week-end
 * is correct, not an approximation. Weeks are ISO-ish: each point is
 * "N*7 days ago at end of day", newest last.
 */
class AtRiskTrendBuilder
{
    public function __construct(
        private readonly RetentionAnalyzer $retention,
    ) {
    }

    /**
     * @return array<int, array{weekEnding: string, count: int}> oldest-first
     */
    public function weeklyTrend(int $weeks, ?Branch $branch = null, ?\DateTimeImmutable $now = null): array
    {
        $weeks = max(1, min(52, $weeks));
        $today = ($now ?? new \DateTimeImmutable())->setTime(0, 0);

        $points = [];
        for ($i = $weeks - 1; $i >= 0; --$i) {
            $weekEnd = $today->modify(sprintf('-%d days', $i * 7));
            $points[] = [
                'weekEnding' => $weekEnd->format('Y-m-d'),
                'count' => count($this->retention->atRiskMembers($weekEnd, $branch)),
            ];
        }

        return $points;
    }
}

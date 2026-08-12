<?php

namespace App\Reporting;

use App\Entity\DailyMetricSnapshot;
use App\Entity\Membership;
use App\Repository\MembershipRepository;

/**
 * architecture doc §6.8: "deliberately statistical, not machine-
 * learning." A weighted moving average over trailing
 * DAILY_METRIC_SNAPSHOT.revenue (more recent days weighted higher),
 * adjusted for known upcoming membership expirations without auto-renew.
 * No ML library, no external prediction service — plain arithmetic over
 * real rows, and every number in the result can be traced back to a
 * specific, named calculation (see `method` on the result).
 */
class RevenueForecaster
{
    /** functional requirements §10.3: "too little historical data" threshold. */
    private const MIN_HISTORY_DAYS = 14;

    /**
     * Losing every non-renewing member's exact pace would overstate the
     * hit (new enrollments keep happening too, and not every non-renewal
     * happens exactly on schedule) — this dampens the adjustment to a
     * directional correction rather than a precise loss model. A stated,
     * deliberate simplification, not an oversight.
     */
    private const RENEWAL_RISK_DAMPENING = 0.5;

    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    /** @param DailyMetricSnapshot[] $snapshots oldest-first */
    public function forecast(array $snapshots, int $horizonDays, \DateTimeImmutable $asOf): RevenueForecastResult
    {
        $historicalPoints = array_map(
            fn (DailyMetricSnapshot $s) => new RevenueForecastPoint($s->getSnapshotDate(), $s->getRevenue()),
            $snapshots,
        );

        if (count($snapshots) < self::MIN_HISTORY_DAYS) {
            return new RevenueForecastResult(false, $historicalPoints, []);
        }

        $baselineDailyRevenue = $this->weightedMovingAverage($snapshots);
        [$adjustmentFactor, $expiringWithoutRenewal, $activeCount] = $this->renewalRiskAdjustment($asOf, $horizonDays);
        $adjustedDailyRevenue = $baselineDailyRevenue * $adjustmentFactor;

        $lastDate = $snapshots[count($snapshots) - 1]->getSnapshotDate();
        $projected = [];
        for ($day = 1; $day <= $horizonDays; ++$day) {
            $projected[] = new RevenueForecastPoint(
                $lastDate->modify("+{$day} day"),
                number_format($adjustedDailyRevenue, 2, '.', ''),
            );
        }

        $method = sprintf(
            'Weighted moving average over the last %d day%s ($%.2f/day baseline), adjusted for %d of %d active member%s expiring without auto-renew in this window',
            count($snapshots),
            count($snapshots) === 1 ? '' : 's',
            $baselineDailyRevenue,
            $expiringWithoutRenewal,
            $activeCount,
            $activeCount === 1 ? '' : 's',
        );

        return new RevenueForecastResult(true, $historicalPoints, $projected, $method);
    }

    /** @param DailyMetricSnapshot[] $snapshots oldest-first */
    private function weightedMovingAverage(array $snapshots): float
    {
        $weightedSum = 0.0;
        $weightTotal = 0;
        foreach (array_values($snapshots) as $index => $snapshot) {
            $weight = $index + 1; // oldest = 1 ... newest = n
            $weightedSum += $weight * (float) $snapshot->getRevenue();
            $weightTotal += $weight;
        }

        return $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;
    }

    /** @return array{0: float, 1: int, 2: int} [adjustmentFactor, expiringWithoutRenewalCount, activeCount] */
    private function renewalRiskAdjustment(\DateTimeImmutable $asOf, int $horizonDays): array
    {
        $activeMemberships = $this->memberships->findWithinTermAsOf($asOf);
        $activeCount = count($activeMemberships);
        $horizonEnd = $asOf->modify("+{$horizonDays} days");

        $expiringWithoutRenewal = count(array_filter(
            $activeMemberships,
            fn (Membership $m) => !$m->isAutoRenew() && $m->getEndDate() >= $asOf && $m->getEndDate() <= $horizonEnd,
        ));

        if ($activeCount === 0) {
            return [1.0, 0, 0];
        }

        $riskRatio = $expiringWithoutRenewal / $activeCount;
        $adjustmentFactor = max(0.0, 1 - $riskRatio * self::RENEWAL_RISK_DAMPENING);

        return [$adjustmentFactor, $expiringWithoutRenewal, $activeCount];
    }
}

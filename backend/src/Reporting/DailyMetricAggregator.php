<?php

namespace App\Reporting;

use App\Entity\Branch;
use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use App\Repository\AttendanceLogRepository;
use App\Repository\BranchRepository;
use App\Repository\DailyMetricSnapshotRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * architecture doc §6.8: nightly aggregation job, computing one
 * DAILY_METRIC_SNAPSHOT row per gym per day from ATTENDANCE_LOG,
 * MEMBERSHIP, and INVOICE. The exact same aggregate() method backs both
 * the steady-state nightly run (yesterday, via RunDailyMetricAggregation
 * MessageHandler) and the one-time historical backfill() below — one
 * formula, so there's no way for live and backfilled days to disagree.
 *
 * roadmap Phase 16: aggregate() takes an optional $branch — null (the
 * default) is the gym-wide rollup, exactly the pre-Phase-16 unfiltered
 * behavior; a Branch narrows every underlying count to that branch alone.
 * backfill() and the nightly handler both now compute the rollup AND
 * every one of the gym's branches, not one row per day.
 *
 * Known, documented limitation (roadmap Phase 11's backfill requirement,
 * honestly scoped): `cancelled_members_count` for any day before
 * Membership::cancelledAt started being recorded is always 0 for
 * memberships cancelled prior to that field existing — the exact
 * cancellation date genuinely isn't recoverable for them. Every other
 * number here is reconstructed from permanent, always-accurate
 * historical facts (check-in timestamps, membership start/end dates,
 * invoice paid_at), so backfilled history is otherwise exact.
 */
class DailyMetricAggregator
{
    public function __construct(
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly MembershipRepository $memberships,
        private readonly InvoiceRepository $invoices,
        private readonly DailyMetricSnapshotRepository $snapshots,
        private readonly RetentionAnalyzer $retention,
        private readonly BranchRepository $branches,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Computes (or recomputes, idempotently) one gym's snapshot for one calendar day — gym-wide if $branch is null, that branch alone otherwise. */
    public function aggregate(Gym $gym, \DateTimeImmutable $date, ?Branch $branch = null): DailyMetricSnapshot
    {
        $date = $this->toDateOnly($date);

        $checkinsCount = $this->attendanceLogs->countForDate($date, $branch);
        $activeMembersCount = $this->memberships->countWithinTermOnDate($date, $branch);
        $newMembersCount = $this->memberships->countStartedOnDate($date, $branch);
        $cancelledMembersCount = $this->memberships->countCancelledOnDate($date, $branch);
        $revenue = $this->invoices->sumPaidAmountOnDate($date, $branch);
        $atRiskMembersCount = count($this->retention->atRiskMembers($date, $branch));

        $existing = $this->snapshots->findOneForDate($gym, $date, $branch);
        if ($existing !== null) {
            $existing->update($checkinsCount, $activeMembersCount, $newMembersCount, $cancelledMembersCount, $revenue, $atRiskMembersCount);
            $this->em->flush();

            return $existing;
        }

        $snapshot = new DailyMetricSnapshot(
            $gym,
            $date,
            $checkinsCount,
            $activeMembersCount,
            $newMembersCount,
            $cancelledMembersCount,
            $revenue,
            $atRiskMembersCount,
            $branch,
        );
        $this->em->persist($snapshot);
        $this->em->flush();

        return $snapshot;
    }

    /**
     * Backfills every day from the gym's earliest known activity through
     * yesterday, for the gym-wide rollup AND every one of the gym's
     * branches. Today's own row is deliberately left for tonight's
     * regular scheduled run — architecture doc §6.8: "reconciled into the
     * snapshot at day's end," i.e. only once today has actually finished.
     *
     * @return int number of calendar days backfilled (not day×branch rows — matches this method's pre-Phase-16 meaning, since BackfillDailyMetricsCommand reports this to the operator as "days").
     */
    public function backfill(Gym $gym): int
    {
        $earliest = $this->earliestKnownDate();
        if ($earliest === null) {
            return 0;
        }

        $branches = $this->branches->findByGym($gym);
        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');
        $count = 0;
        for ($date = $this->toDateOnly($earliest); $date <= $yesterday; $date = $date->modify('+1 day')) {
            $this->aggregate($gym, $date);
            foreach ($branches as $branch) {
                $this->aggregate($gym, $date, $branch);
            }
            ++$count;
        }

        return $count;
    }

    private function earliestKnownDate(): ?\DateTimeImmutable
    {
        $candidates = array_filter([
            $this->attendanceLogs->findEarliestCheckInDate(),
            $this->memberships->findEarliestStartDate(),
            $this->invoices->findEarliestPaidAtDate(),
        ]);

        return $candidates === [] ? null : min($candidates);
    }

    private function toDateOnly(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0);
    }
}

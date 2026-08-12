<?php

namespace App\Reporting;

use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use App\Repository\AttendanceLogRepository;
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
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Computes (or recomputes, idempotently) one gym's snapshot for one calendar day. */
    public function aggregate(Gym $gym, \DateTimeImmutable $date): DailyMetricSnapshot
    {
        $date = $this->toDateOnly($date);

        $checkinsCount = $this->attendanceLogs->countForDate($date);
        $activeMembersCount = $this->memberships->countWithinTermOnDate($date);
        $newMembersCount = $this->memberships->countStartedOnDate($date);
        $cancelledMembersCount = $this->memberships->countCancelledOnDate($date);
        $revenue = $this->invoices->sumPaidAmountOnDate($date);
        $atRiskMembersCount = count($this->retention->atRiskMembers($date));

        $existing = $this->snapshots->findOneForDate($gym, $date);
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
        );
        $this->em->persist($snapshot);
        $this->em->flush();

        return $snapshot;
    }

    /**
     * Backfills every day from the gym's earliest known activity through
     * yesterday. Today's own row is deliberately left for tonight's
     * regular scheduled run — architecture doc §6.8: "reconciled into the
     * snapshot at day's end," i.e. only once today has actually finished.
     *
     * @return int number of days backfilled
     */
    public function backfill(Gym $gym): int
    {
        $earliest = $this->earliestKnownDate();
        if ($earliest === null) {
            return 0;
        }

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');
        $count = 0;
        for ($date = $this->toDateOnly($earliest); $date <= $yesterday; $date = $date->modify('+1 day')) {
            $this->aggregate($gym, $date);
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

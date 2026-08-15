<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Enum\MembershipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /** functional requirements §3.1: a plan with an active/paused membership can't be silently deleted. */
    public function hasOngoingMembership(MembershipPlan $plan): bool
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.plan = :plan')
            ->andWhere('m.status IN (:ongoing)')
            ->setParameter('plan', $plan)
            ->setParameter('ongoing', [MembershipStatus::ACTIVE, MembershipStatus::PAUSED])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findOneOngoingForMember(MemberProfile $member): ?Membership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.member = :member')
            ->andWhere('m.status IN (:ongoing)')
            ->setParameter('member', $member)
            ->setParameter('ongoing', [MembershipStatus::ACTIVE, MembershipStatus::PAUSED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Most recent membership regardless of status — "My membership" still shows an expired/cancelled one rather than nothing. */
    public function findMostRecentForMember(MemberProfile $member): ?Membership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.member = :member')
            ->setParameter('member', $member)
            ->orderBy('m.startDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** architecture doc §8.3: memberships crossing the 7/3/1-day-before-expiry threshold today. */
    public function findActiveExpiringInDays(int $days): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :active')
            ->andWhere('m.endDate = :target')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->setParameter('target', new \DateTimeImmutable("+{$days} days"))
            ->getQuery()
            ->getResult();
    }

    /** Active memberships whose end date has already passed — due to transition to expired. */
    public function findActivePastEndDate(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :active')
            ->andWhere('m.endDate < :today')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()
            ->getResult();
    }

    /**
     * roadmap Phase 11 / DailyMetricAggregator: "was this membership
     * within its paid term on day D" — computed purely from start_date/
     * end_date (both permanent, never-changing facts), not the possibly
     * lazily-stale `status` column, so it's equally correct for today and
     * for historical backfill. CANCELLED is excluded outright: a
     * cancelled membership has no recorded cancellation date before
     * Phase 11 (see Membership::cancelledAt's docblock), so treating it
     * as "never active" for historical counting is the honest choice —
     * see DailyMetricAggregator's own docblock for the fuller reasoning.
     */
    public function countWithinTermOnDate(\DateTimeImmutable $date, ?Branch $branch = null): int
    {
        return (int) $this->withBranch($this->createQueryBuilder('m'), $branch)
            ->select('COUNT(m.id)')
            ->andWhere('m.status != :cancelled')
            ->andWhere('m.startDate <= :date')
            ->andWhere('m.endDate >= :date')
            ->setParameter('cancelled', MembershipStatus::CANCELLED)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** New enrollments on a given day — start_date is set once at enroll() and never changes. */
    public function countStartedOnDate(\DateTimeImmutable $date, ?Branch $branch = null): int
    {
        return (int) $this->withBranch($this->createQueryBuilder('m'), $branch)
            ->select('COUNT(m.id)')
            ->andWhere('m.startDate = :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Cancellations recorded on a given day (only meaningful since Membership::cancelledAt started being set — see its docblock). */
    public function countCancelledOnDate(\DateTimeImmutable $date, ?Branch $branch = null): int
    {
        return (int) $this->withBranch($this->createQueryBuilder('m'), $branch)
            ->select('COUNT(m.id)')
            ->andWhere('m.cancelledAt >= :start')
            ->andWhere('m.cancelledAt < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $date->modify('+1 day'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** roadmap Phase 16: joins through the plan to filter by branch — MEMBERSHIP itself has no branch_id, only MEMBERSHIP_PLAN does. */
    private function withBranch(QueryBuilder $qb, ?Branch $branch): QueryBuilder
    {
        if ($branch !== null) {
            $qb->innerJoin('m.plan', 'p')
                ->andWhere('p.branch = :branch')
                ->setParameter('branch', $branch);
        }

        return $qb;
    }

    /** Earliest enrollment on record — DailyMetricAggregator's backfill start bound. */
    public function findEarliestStartDate(): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('m')
            ->select('MIN(m.startDate) as earliest')
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? new \DateTimeImmutable($result) : null;
    }

    /**
     * roadmap Phase 11 / RetentionAnalyzer: active memberships "as of" a
     * point in time — start_date/end_date bound the term the same way
     * countWithinTermOnDate() does, so this stays accurate whether called
     * live (asOf = now) or from the aggregator's historical backfill.
     *
     * @return Membership[]
     */
    public function findWithinTermAsOf(\DateTimeImmutable $asOf, ?Branch $branch = null): array
    {
        return $this->withBranch($this->createQueryBuilder('m'), $branch)
            ->andWhere('m.status != :cancelled')
            ->andWhere('m.startDate <= :asOf')
            ->andWhere('m.endDate >= :asOf')
            ->setParameter('cancelled', MembershipStatus::CANCELLED)
            ->setParameter('asOf', $asOf)
            ->getQuery()
            ->getResult();
    }
}

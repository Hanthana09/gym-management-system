<?php

namespace App\Repository;

use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\MemberProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttendanceLog>
 *
 * architecture doc §5.1's ATTENDANCE_LOG has no gym_id column, and this is
 * a single-gym product (CLAUDE.md) — every query here is effectively
 * "the gym's" attendance without needing a join through
 * member → membership → plan → gym just to scope by a single tenant.
 * roadmap Phase 16: an optional $branch narrows further to one branch —
 * omitted (null) keeps every method's exact pre-Phase-16 (gym-wide)
 * behavior, which is what makes the single-branch regression case hold.
 */
class AttendanceLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceLog::class);
    }

    public function countSince(\DateTimeImmutable $since, ?Branch $branch = null): int
    {
        return (int) $this->withBranch($this->createQueryBuilder('a'), $branch)
            ->select('COUNT(a.id)')
            ->andWhere('a.checkIn >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** roadmap Phase 11: one day's check-in count for DailyMetricAggregator (live "today" and historical backfill alike). */
    public function countForDate(\DateTimeImmutable $date, ?Branch $branch = null): int
    {
        return (int) $this->withBranch($this->createQueryBuilder('a'), $branch)
            ->select('COUNT(a.id)')
            ->andWhere('a.checkIn >= :start')
            ->andWhere('a.checkIn < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $date->modify('+1 day'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Earliest check-in on record — DailyMetricAggregator's backfill start bound. */
    public function findEarliestCheckInDate(): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('a')
            ->select('MIN(a.checkIn) as earliest')
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? new \DateTimeImmutable($result) : null;
    }

    /** @return AttendanceLog[] */
    public function findByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?Branch $branch = null): array
    {
        return $this->withBranch($this->createQueryBuilder('a'), $branch)
            ->andWhere('a.checkIn >= :from')
            ->andWhere('a.checkIn < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.checkIn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function withBranch(QueryBuilder $qb, ?Branch $branch): QueryBuilder
    {
        if ($branch !== null) {
            $qb->andWhere('a.branch = :branch')->setParameter('branch', $branch);
        }

        return $qb;
    }

    /** roadmap Phase 9.3: raw material for streak calculation — newest first. */
    public function findAllForMember(MemberProfile $member): array
    {
        return $this->findBy(['member' => $member], ['checkIn' => 'DESC']);
    }

    /** Branch delete facility: a branch with any attendance ever recorded against it can't be hard-deleted (functional requirements §14.1 — history must stay reportable). */
    public function existsForBranch(Branch $branch): bool
    {
        return $this->createQueryBuilder('a')
            ->select('1')
            ->andWhere('a.branch = :branch')
            ->setParameter('branch', $branch)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * Top-bar check-in timer feature: "today's active session" (a member
     * still checked in — no checkOut yet). Scoped to today so a session
     * someone forgot to check out of yesterday doesn't linger as "active"
     * forever (there's no auto-checkout job yet — separate task). Most
     * recent first + single result as a safety net: nothing currently
     * stops a second check-in before the first is checked out.
     */
    public function findOpenForMember(MemberProfile $member): ?AttendanceLog
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.member = :member')
            ->andWhere('a.checkOut IS NULL')
            ->andWhere('a.checkIn >= :todayStart')
            ->setParameter('member', $member)
            ->setParameter('todayStart', new \DateTimeImmutable('today'))
            ->orderBy('a.checkIn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

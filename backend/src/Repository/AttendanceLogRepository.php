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
    /** Eager-loads member + member.user: both existing callers (OwnerDashboardPage's Attendance tab) and the new Staff dashboard's recent-activity widget serialize memberName per row (N+1 otherwise). */
    public function findByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?Branch $branch = null): array
    {
        return $this->withBranch($this->createQueryBuilder('a'), $branch)
            ->innerJoin('a.member', 'm')
            ->addSelect('m')
            ->innerJoin('m.user', 'u')
            ->addSelect('u')
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

    /**
     * Raw check-in instants from $since (inclusive) onward — the material
     * for the home-dashboard "peak hours" chart (Owner analytics slice).
     * DQL has no EXTRACT(DOW|HOUR), and this codebase uses no native SQL,
     * so the day-of-week × hour bucketing happens in PHP (PeakHoursAnalyzer)
     * — the same "roll the rows up in PHP" shape ReportController already
     * uses for daily check-in counts. A 30-day window is a few thousand
     * rows at most; if that ever bites, cache at the HTTP layer, don't
     * pre-aggregate (§Phase 11 note).
     *
     * @return \DateTimeImmutable[]
     */
    public function checkInInstantsSince(\DateTimeImmutable $since, ?Branch $branch = null): array
    {
        $rows = $this->withBranch($this->createQueryBuilder('a'), $branch)
            ->select('a.checkIn AS checkIn')
            ->andWhere('a.checkIn >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        // Scalar DQL hydration returns a DateTimeImmutable on some drivers,
        // a raw datetime string on others — normalize to DateTimeImmutable.
        return array_map(static function (array $row) {
            $value = $row['checkIn'];

            return $value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string) $value);
        }, $rows);
    }

    /** roadmap Phase 9.3: raw material for streak calculation — newest first. */
    public function findAllForMember(MemberProfile $member): array
    {
        return $this->findBy(['member' => $member], ['checkIn' => 'DESC']);
    }

    /**
     * gym-management-member-profile-extension.md §5: Owner Member
     * Detail's Attendance tab — same "newest first" ordering as
     * findAllForMember(), paginated instead of returning the full
     * history at once.
     *
     * @return AttendanceLog[]
     */
    /** Eager-loads branch: every caller serializes it per row (Owner's Attendance tab, the Member dashboard's recent-attendance widget), which would otherwise lazy-load per row (N+1). */
    public function findPaginatedForMember(MemberProfile $member, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.branch', 'b')
            ->addSelect('b')
            ->andWhere('a.member = :member')
            ->setParameter('member', $member)
            ->orderBy('a.checkIn', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countForMember(MemberProfile $member): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.member = :member')
            ->setParameter('member', $member)
            ->getQuery()
            ->getSingleScalarResult();
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

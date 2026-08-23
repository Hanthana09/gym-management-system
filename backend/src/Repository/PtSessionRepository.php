<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Enum\PtSessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PtSession>
 *
 * Single-gym product (CLAUDE.md) — no gym_id scoping needed, same
 * reasoning as AttendanceLogRepository.
 */
class PtSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PtSession::class);
    }

    /**
     * Member's "My sessions" list — newest requested first. Eager-loads
     * coach/coach.user/branch: every caller (MemberController's PT
     * schedule, PtSessionController's list, the dashboard's next-session
     * lookup) serializes at least one of those per row, which would
     * otherwise lazy-load per row (N+1) — purely additive, doesn't change
     * the result shape.
     */
    public function findForMember(MemberProfile $member): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.coach', 'c')
            ->addSelect('c')
            ->innerJoin('c.user', 'cu')
            ->addSelect('cu')
            ->innerJoin('s.branch', 'b')
            ->addSelect('b')
            ->andWhere('s.member = :member')
            ->setParameter('member', $member)
            ->orderBy('s.scheduledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Coach's agenda — soonest scheduled first. */
    public function findForCoach(CoachProfile $coach): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('s.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Branch delete facility: a branch with any PT session (any status, past or present) recorded against it can't be hard-deleted. */
    public function existsForBranch(Branch $branch): bool
    {
        return $this->count(['branch' => $branch]) > 0;
    }

    /** gym-management-dashboard-redesign.md Phase 3: Coach dashboard's "today's sessions" list — one branch at a time, never blended. */
    public function findForCoachBranchAndDate(CoachProfile $coach, Branch $branch, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.member', 'm')
            ->addSelect('m')
            ->innerJoin('m.user', 'mu')
            ->addSelect('mu')
            ->andWhere('s.coach = :coach')
            ->andWhere('s.branch = :branch')
            ->andWhere('s.scheduledAt >= :start')
            ->andWhere('s.scheduledAt < :end')
            ->setParameter('coach', $coach)
            ->setParameter('branch', $branch)
            ->setParameter('start', $date)
            ->setParameter('end', $date->modify('+1 day'))
            ->orderBy('s.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Coach dashboard's "assigned members" count — distinct members with any session at this branch, ever (not just today's). */
    public function countDistinctMembersForCoachAndBranch(CoachProfile $coach, Branch $branch): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.member)')
            ->andWhere('s.coach = :coach')
            ->andWhere('s.branch = :branch')
            ->setParameter('coach', $coach)
            ->setParameter('branch', $branch)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Coach dashboard's weekly utilization bar: confirmed/completed sessions in a date range, one branch at a time. */
    public function countConfirmedOrCompletedForCoachBranchInRange(CoachProfile $coach, Branch $branch, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.coach = :coach')
            ->andWhere('s.branch = :branch')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.scheduledAt >= :from')
            ->andWhere('s.scheduledAt < :to')
            ->setParameter('coach', $coach)
            ->setParameter('branch', $branch)
            ->setParameter('statuses', [PtSessionStatus::CONFIRMED, PtSessionStatus::COMPLETED])
            ->setParameter('from', $from)
            ->setParameter('to', $toExclusive)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * roadmap Phase 17 / FinancialSummaryController: raw material for the
     * read-time PT revenue estimate — architecture doc §6.13:
     * "Σ (coach.hourly_rate × session.duration_minutes / 60) over
     * PT_SESSION rows with status confirmed or completed in that
     * range/branch." This returns the matching rows (coach eager-loaded,
     * since the sum needs each one's hourly_rate); the actual arithmetic
     * is done by the controller, matching how RevenueForecaster/
     * RetentionAnalyzer compute derived figures on read rather than here.
     *
     * @return PtSession[]
     */
    public function findConfirmedOrCompletedInRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.coach', 'c')
            ->addSelect('c')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.scheduledAt >= :from')
            ->andWhere('s.scheduledAt < :to')
            ->setParameter('statuses', [PtSessionStatus::CONFIRMED, PtSessionStatus::COMPLETED])
            ->setParameter('from', $from)
            ->setParameter('to', $toExclusive);

        if ($branch !== null) {
            $qb->andWhere('s.branch = :branch')->setParameter('branch', $branch);
        }

        return $qb->getQuery()->getResult();
    }
}

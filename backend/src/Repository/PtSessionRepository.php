<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
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

    /** Member's "My sessions" list — newest requested first. */
    public function findForMember(MemberProfile $member): array
    {
        return $this->createQueryBuilder('s')
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
}

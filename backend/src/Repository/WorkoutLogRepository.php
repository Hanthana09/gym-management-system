<?php

namespace App\Repository;

use App\Entity\MemberProfile;
use App\Entity\WorkoutLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkoutLog>
 */
class WorkoutLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkoutLog::class);
    }

    /** functional requirements §7.1: "appears immediately in my history, newest first." */
    public function findForMember(MemberProfile $member): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.member = :member')
            ->setParameter('member', $member)
            ->orderBy('w.date', 'DESC')
            ->addOrderBy('w.id', 'DESC') // tie-break same-day entries (see NotificationRepository for why)
            ->getQuery()
            ->getResult();
    }
}

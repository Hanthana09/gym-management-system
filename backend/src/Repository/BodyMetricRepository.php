<?php

namespace App\Repository;

use App\Entity\BodyMetric;
use App\Entity\MemberProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BodyMetric>
 */
class BodyMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BodyMetric::class);
    }

    /** Chronological (oldest first) — what the progress chart plots. */
    public function findForMember(MemberProfile $member): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.member = :member')
            ->setParameter('member', $member)
            ->orderBy('b.date', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

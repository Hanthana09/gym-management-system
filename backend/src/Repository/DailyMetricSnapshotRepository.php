<?php

namespace App\Repository;

use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyMetricSnapshot>
 */
class DailyMetricSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyMetricSnapshot::class);
    }

    public function findOneForDate(Gym $gym, \DateTimeImmutable $date): ?DailyMetricSnapshot
    {
        return $this->findOneBy(['gym' => $gym, 'snapshotDate' => $date]);
    }

    /**
     * Inclusive [from, to] range, ordered oldest-first — what every trend
     * chart and the revenue forecaster read.
     *
     * @return DailyMetricSnapshot[]
     */
    public function findForDateRange(Gym $gym, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.gym = :gym')
            ->andWhere('s.snapshotDate >= :from')
            ->andWhere('s.snapshotDate <= :to')
            ->setParameter('gym', $gym)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

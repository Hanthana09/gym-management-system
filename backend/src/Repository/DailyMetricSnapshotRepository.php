<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyMetricSnapshot>
 *
 * roadmap Phase 16: $branch = null means the gym-wide rollup row
 * specifically — never "any branch," which is why every method here
 * filters on `s.branch IS NULL` rather than just omitting a branch
 * clause when $branch isn't given (that would return whichever row
 * happened to exist for the date, branch-specific or not).
 */
class DailyMetricSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyMetricSnapshot::class);
    }

    public function findOneForDate(Gym $gym, \DateTimeImmutable $date, ?Branch $branch = null): ?DailyMetricSnapshot
    {
        return $this->findOneBy(['gym' => $gym, 'snapshotDate' => $date, 'branch' => $branch]);
    }

    /**
     * Inclusive [from, to] range, ordered oldest-first — what every trend
     * chart and the revenue forecaster read.
     *
     * @return DailyMetricSnapshot[]
     */
    public function findForDateRange(Gym $gym, \DateTimeImmutable $from, \DateTimeImmutable $to, ?Branch $branch = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.gym = :gym')
            ->andWhere('s.snapshotDate >= :from')
            ->andWhere('s.snapshotDate <= :to')
            ->setParameter('gym', $gym)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.snapshotDate', 'ASC');

        if ($branch !== null) {
            $qb->andWhere('s.branch = :branch')->setParameter('branch', $branch);
        } else {
            $qb->andWhere('s.branch IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Per-branch snapshot rows (never the gym-wide rollup) across an
     * inclusive [from, to] range, oldest-first — the raw material for the
     * home-dashboard "branch comparison" chart. Revenue / check-ins are
     * summed over the range by the caller; active-members is a
     * point-in-time count, so the caller takes the most recent row's
     * value rather than summing it.
     *
     * @return DailyMetricSnapshot[]
     */
    public function findPerBranchForDateRange(Gym $gym, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('b')
            ->innerJoin('s.branch', 'b')
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

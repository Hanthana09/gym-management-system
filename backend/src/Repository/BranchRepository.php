<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\Gym;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Branch>
 */
class BranchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Branch::class);
    }

    /** @return Branch[] */
    public function findByGym(Gym $gym): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.gym = :gym')
            ->setParameter('gym', $gym)
            ->orderBy('b.isPrimary', 'DESC')
            ->addOrderBy('b.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPrimaryForGym(Gym $gym): ?Branch
    {
        return $this->findOneBy(['gym' => $gym, 'isPrimary' => true]);
    }

    /** roadmap Phase 16 DoD: single-branch UI/behavior detection — DESIGN-SYSTEM.md §4.2's "does not render at all" rule. */
    public function countForGym(Gym $gym): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.gym = :gym')
            ->setParameter('gym', $gym)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

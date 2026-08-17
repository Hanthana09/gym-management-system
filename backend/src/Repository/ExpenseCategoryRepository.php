<?php

namespace App\Repository;

use App\Entity\ExpenseCategory;
use App\Entity\Gym;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExpenseCategory>
 */
class ExpenseCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseCategory::class);
    }

    /** @return ExpenseCategory[] */
    public function findByGym(Gym $gym): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.gym = :gym')
            ->setParameter('gym', $gym)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForGym(Gym $gym): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.gym = :gym')
            ->setParameter('gym', $gym)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

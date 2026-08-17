<?php

namespace App\Repository;

use App\Entity\Gym;
use App\Entity\Product;
use App\Entity\ProductCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return Product[] */
    public function findByGym(Gym $gym, bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.gym = :gym')
            ->setParameter('gym', $gym)
            ->orderBy('p.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('p.isActive = true');
        }

        return $qb->getQuery()->getResult();
    }

    /** ProductCategoryController::delete() — block deletion rather than orphaning existing Product rows (same rule as MembershipPlan's ongoing-memberships check). */
    public function existsForCategory(ProductCategory $category): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }
}

<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Entity\Product;
use App\Entity\ProductSale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductSale>
 *
 * roadmap Phase 17: no gym_id column on PRODUCT_SALE — gym is derivable
 * via branch, same single-gym-product collapse as ExpenseRepository.
 * `sale_date` is a timestamp (not a plain date), so day-boundary methods
 * here use a `[start, start+1day)` range, matching
 * AttendanceLogRepository::countForDate()'s convention rather than
 * ExpenseRepository's plain-date equality.
 */
class ProductSaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductSale::class);
    }

    /** DailyMetricAggregator's per-day retail_revenue figure. */
    public function sumTotalAmountOnDate(\DateTimeImmutable $date, ?Branch $branch = null): string
    {
        $qb = $this->withBranch($this->createQueryBuilder('s'), $branch)
            ->select('SUM(s.totalAmount)')
            ->andWhere('s.saleDate >= :start')
            ->andWhere('s.saleDate < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $date->modify('+1 day'));

        $sum = $qb->getQuery()->getSingleScalarResult();

        return $sum ?? '0.00';
    }

    /** FinancialSummaryController: total retail revenue over an arbitrary [from, toExclusive) range. */
    public function sumTotalAmountForDateRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch = null): string
    {
        $qb = $this->withBranch($this->createQueryBuilder('s'), $branch)
            ->select('SUM(s.totalAmount)')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $toExclusive);

        $sum = $qb->getQuery()->getSingleScalarResult();

        return $sum ?? '0.00';
    }

    /**
     * Retail sale list endpoint, filterable by branch/product/member/date
     * range per architecture doc §7's `GET /product-sales`.
     *
     * @param Branch[]|null $branches null = every branch (Owner rollup); an array = restrict to exactly these (Staff's assigned branches, or a single explicit ?branch_id)
     *
     * @return ProductSale[]
     */
    public function findByFilters(
        ?array $branches,
        ?Product $product,
        ?MemberProfile $member,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.saleDate', 'DESC');

        if ($branches !== null) {
            $qb->andWhere('s.branch IN (:branches)')->setParameter('branches', $branches);
        }
        if ($product !== null) {
            $qb->andWhere('s.product = :product')->setParameter('product', $product);
        }
        if ($member !== null) {
            $qb->andWhere('s.member = :member')->setParameter('member', $member);
        }
        if ($from !== null) {
            $qb->andWhere('s.saleDate >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('s.saleDate < :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    private function withBranch(QueryBuilder $qb, ?Branch $branch): QueryBuilder
    {
        if ($branch !== null) {
            $qb->andWhere('s.branch = :branch')->setParameter('branch', $branch);
        }

        return $qb;
    }
}

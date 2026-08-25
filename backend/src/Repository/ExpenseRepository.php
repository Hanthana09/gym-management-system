<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expense>
 *
 * roadmap Phase 17: no gym_id column on EXPENSE (architecture doc §5.1 —
 * gym is derivable via branch), same single-gym-product collapse already
 * used by AttendanceLogRepository/PtSessionRepository. `$branch = null`
 * means "every branch" for the Owner's rollup views (list/dashboard), the
 * same convention AttendanceLogRepository/InvoiceRepository already use.
 */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /**
     * DailyMetricAggregator's per-day figure — a single calendar day
     * (`expense_date` is a plain date column, so this is exact equality,
     * not a timestamp range like InvoiceRepository::sumPaidAmountOnDate).
     */
    public function sumAmountOnDate(\DateTimeImmutable $date, ?Branch $branch = null): string
    {
        $qb = $this->withBranch($this->createQueryBuilder('e'), $branch)
            ->select('SUM(e.amount)')
            ->andWhere('e.expenseDate = :date')
            ->setParameter('date', $date->format('Y-m-d'));

        $sum = $qb->getQuery()->getSingleScalarResult();

        return $sum ?? '0.00';
    }

    /**
     * DailyMetricAggregator's `expense_by_category` JSON column —
     * category_id (string) => amount (string), same flexible-data
     * rationale as WORKOUT_LOG.metrics (§5.2).
     *
     * @return array<string, string>
     */
    public function amountByCategoryOnDate(\DateTimeImmutable $date, ?Branch $branch = null): array
    {
        $qb = $this->withBranch($this->createQueryBuilder('e'), $branch)
            ->select('IDENTITY(e.category) as categoryId', 'SUM(e.amount) as total')
            ->andWhere('e.expenseDate = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->groupBy('e.category');

        $rows = $qb->getQuery()->getResult();

        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[(string) $row['categoryId']] = $row['total'];
        }

        return $byCategory;
    }

    /**
     * Owner dashboard donut chart: per-category totals over an arbitrary
     * [from, toExclusive) range, category name included directly (one
     * query, no N+1 lookup against ExpenseCategoryRepository per row).
     *
     * @return array<int, array{categoryId: string, categoryName: string, total: string}>
     */
    public function amountByCategoryForDateRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch = null): array
    {
        $qb = $this->withBranch($this->createQueryBuilder('e'), $branch)
            ->select('IDENTITY(e.category) as categoryId', 'c.name as categoryName', 'SUM(e.amount) as total')
            ->join('e.category', 'c')
            ->andWhere('e.expenseDate >= :from')
            ->andWhere('e.expenseDate < :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $toExclusive->format('Y-m-d'))
            ->groupBy('e.category', 'c.name')
            ->orderBy('total', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /** FinancialSummaryController: total expenses over an arbitrary [from, toExclusive) range. */
    public function sumAmountForDateRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch = null): string
    {
        $qb = $this->withBranch($this->createQueryBuilder('e'), $branch)
            ->select('SUM(e.amount)')
            ->andWhere('e.expenseDate >= :from')
            ->andWhere('e.expenseDate < :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $toExclusive->format('Y-m-d'));

        $sum = $qb->getQuery()->getSingleScalarResult();

        return $sum ?? '0.00';
    }

    /**
     * Expense list endpoint (Owner: any branch; Staff: own assigned
     * branch(es) only — the controller passes the already-scoped branch
     * set). $category/$from/$to are optional filters per functional
     * requirements §15.1's list screen.
     *
     * @param Branch[]|null $branches null = every branch (Owner rollup); an array = restrict to exactly these (Staff's assigned branches, or a single explicit ?branch_id)
     *
     * @return Expense[]
     */
    public function findByFilters(?array $branches, ?ExpenseCategory $category, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.expenseDate', 'DESC')
            ->addOrderBy('e.createdAt', 'DESC');

        if ($branches !== null) {
            $qb->andWhere('e.branch IN (:branches)')->setParameter('branches', $branches);
        }
        if ($category !== null) {
            $qb->andWhere('e.category = :category')->setParameter('category', $category);
        }
        if ($from !== null) {
            $qb->andWhere('e.expenseDate >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($to !== null) {
            $qb->andWhere('e.expenseDate <= :to')->setParameter('to', $to->format('Y-m-d'));
        }

        return $qb->getQuery()->getResult();
    }

    /** ExpenseCategoryController::delete() — block deletion rather than orphaning existing Expense rows (same rule as MembershipPlan's ongoing-memberships check). */
    public function existsForCategory(ExpenseCategory $category): bool
    {
        $count = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    private function withBranch(QueryBuilder $qb, ?Branch $branch): QueryBuilder
    {
        if ($branch !== null) {
            $qb->andWhere('e.branch = :branch')->setParameter('branch', $branch);
        }

        return $qb;
    }
}

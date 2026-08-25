<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\Invoice;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * architecture doc §7: GET /invoices (Owner — all invoices for their
     * gym). Single-gym product (CLAUDE.md) — every Invoice belongs to the
     * one Owner, same collapse already used by MemberProfileRepository /
     * CoachProfileRepository's findAllWithUser(), so no gym filter here.
     *
     * @return Invoice[]
     */
    public function findAllOrderedByIssuedAtDesc(): array
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.membership', 'm')
            ->addSelect('m')
            ->innerJoin('m.plan', 'p')
            ->addSelect('p')
            ->orderBy('i.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** architecture doc §7: GET /members/me/invoices (Member — own invoices only). */
    public function findAllForMember(MemberProfile $member): array
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.membership', 'm')
            ->addSelect('m')
            ->innerJoin('m.plan', 'p')
            ->addSelect('p')
            ->andWhere('m.member = :member')
            ->setParameter('member', $member)
            ->orderBy('i.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * roadmap Phase 11 / DailyMetricAggregator: revenue is realized when
     * payment is *recorded* (paid_at), not when the invoice was issued —
     * matching architecture doc §6.9's manual-recording model, and
     * consistent whether the payment method was cash/bank_transfer or the
     * automatic referral-credit path (Phase 10) — both set paid_at.
     */
    public function sumPaidAmountOnDate(\DateTimeImmutable $date, ?Branch $branch = null): string
    {
        $qb = $this->createQueryBuilder('i')
            ->select('SUM(i.amount)')
            ->andWhere('i.status = :paid')
            ->andWhere('i.paidAt >= :start')
            ->andWhere('i.paidAt < :end')
            ->setParameter('paid', InvoiceStatus::PAID)
            ->setParameter('start', $date)
            ->setParameter('end', $date->modify('+1 day'));

        if ($branch !== null) {
            $qb->innerJoin('i.membership', 'm')
                ->innerJoin('m.plan', 'p')
                ->andWhere('p.branch = :branch')
                ->setParameter('branch', $branch);
        }

        $sum = $qb->getQuery()->getSingleScalarResult();

        return $sum ?? '0.00';
    }

    /**
     * roadmap Phase 17 / FinancialSummaryController: the range variant of
     * sumPaidAmountOnDate() above — same "realized when paid, not issued"
     * rule, just over an arbitrary [from, toExclusive) window instead of
     * one calendar day. Purely additive: sumPaidAmountOnDate() itself is
     * untouched, and this reads Invoice, it never writes it — no billing
     * code path is modified to add this.
     */
    public function sumPaidAmountForDateRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch = null): string
    {
        $qb = $this->createQueryBuilder('i')
            ->select('SUM(i.amount)')
            ->andWhere('i.status = :paid')
            ->andWhere('i.paidAt >= :start')
            ->andWhere('i.paidAt < :end')
            ->setParameter('paid', InvoiceStatus::PAID)
            ->setParameter('start', $from)
            ->setParameter('end', $toExclusive);

        if ($branch !== null) {
            $qb->innerJoin('i.membership', 'm')
                ->innerJoin('m.plan', 'p')
                ->andWhere('p.branch = :branch')
                ->setParameter('branch', $branch);
        }

        $sum = $qb->getQuery()->getSingleScalarResult();

        return $sum ?? '0.00';
    }

    /** Earliest payment on record — DailyMetricAggregator's backfill start bound. */
    public function findEarliestPaidAtDate(): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('i')
            ->select('MIN(i.paidAt) as earliest')
            ->andWhere('i.status = :paid')
            ->setParameter('paid', InvoiceStatus::PAID)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? new \DateTimeImmutable($result) : null;
    }

    /** InvoiceGenerationService's idempotency check — a real DB round trip beats relying on catching the unique-constraint violation. */
    public function findOneForMembershipAndPeriodStart(Membership $membership, \DateTimeImmutable $periodStart): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.membership = :membership')
            ->andWhere('i.periodStart = :periodStart')
            ->setParameter('membership', $membership)
            ->setParameter('periodStart', $periodStart)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The recurring invoice most recently generated for this membership (highest periodStart) — InvoiceGenerationService checks whether it's still PENDING before marking it ABSENT. */
    public function findMostRecentRecurringForMembership(Membership $membership): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.membership = :membership')
            ->andWhere('i.periodStart IS NOT NULL')
            ->setParameter('membership', $membership)
            ->orderBy('i.periodStart', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** CheckInEligibilityChecker rule 2 (gym-management-billing-v1.md §5.5) — recurring invoices only, periodStart IS NOT NULL excludes the legacy one-time flow entirely. */
    public function hasAbsentInvoice(Membership $membership): bool
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.membership = :membership')
            ->andWhere('i.periodStart IS NOT NULL')
            ->andWhere('i.status = :absent')
            ->setParameter('membership', $membership)
            ->setParameter('absent', InvoiceStatus::ABSENT)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * CheckInEligibilityChecker rule 3 — evaluated live against dueDate,
     * never dependent on the generation command having run yet (§5.5's
     * explicit note: still PENDING in the DB the day after dueDate, but
     * already blocking).
     */
    public function hasOverduePendingInvoice(Membership $membership, \DateTimeImmutable $today): bool
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.membership = :membership')
            ->andWhere('i.periodStart IS NOT NULL')
            ->andWhere('i.status = :pending')
            ->andWhere('i.dueDate < :today')
            ->setParameter('membership', $membership)
            ->setParameter('pending', InvoiceStatus::PENDING)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** GET /members/{id}/billing-status's outstandingInvoices — ABSENT or PENDING, recurring invoices only, oldest due first. */
    public function findOutstandingForMembership(Membership $membership): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.membership = :membership')
            ->andWhere('i.periodStart IS NOT NULL')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('membership', $membership)
            ->setParameter('statuses', [InvoiceStatus::ABSENT, InvoiceStatus::PENDING])
            ->orderBy('i.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * GET /branches/{id}/invoices?status=absent,overdue — the dashboard
     * "needs attention" widget's data source. `overdue` isn't a stored
     * status (gym-management-billing-v1.md §5.5's note: a PENDING invoice
     * past its dueDate is "overdue" only conceptually until the next
     * generation run formalizes it as ABSENT), so this ORs the two
     * conditions rather than filtering on a single status value.
     *
     * @return Invoice[]
     */
    public function findNeedingAttentionForBranch(Branch $branch, \DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.membership', 'm')
            ->addSelect('m')
            ->innerJoin('m.member', 'mp')
            ->addSelect('mp')
            ->innerJoin('mp.user', 'u')
            ->addSelect('u')
            ->innerJoin('m.plan', 'p')
            ->andWhere('p.branch = :branch')
            ->andWhere('i.periodStart IS NOT NULL')
            ->andWhere('i.status = :absent OR (i.status = :pending AND i.dueDate < :today)')
            ->setParameter('branch', $branch)
            ->setParameter('absent', InvoiceStatus::ABSENT)
            ->setParameter('pending', InvoiceStatus::PENDING)
            ->setParameter('today', $today)
            ->orderBy('i.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

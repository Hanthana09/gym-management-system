<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\Invoice;
use App\Entity\MemberProfile;
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
}

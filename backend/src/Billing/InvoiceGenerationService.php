<?php

namespace App\Billing;

use App\Entity\Invoice;
use App\Entity\Membership;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * gym-management-billing-v1.md §5.1 — the invoice-generation command's
 * actual logic (App\Command\GenerateInvoicesCommand and the Scheduler
 * handler both just delegate here). Exactly one invoice — the immediately
 * preceding one — is touched per cycle boundary per membership.
 */
class InvoiceGenerationService
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{processed: int, skippedAlreadyGenerated: int}
     */
    public function generateForAllDueSubscriptions(): array
    {
        $today = new \DateTimeImmutable('today');
        $processed = 0;
        $skipped = 0;

        foreach ($this->memberships->findDueForBilling($today) as $membership) {
            if ($this->generateOneCycle($membership)) {
                ++$processed;
            } else {
                ++$skipped;
            }
        }

        return ['processed' => $processed, 'skippedAlreadyGenerated' => $skipped];
    }

    /**
     * Idempotent: if a cycle's invoice already exists (re-running the
     * command the same day), this is a no-op — checked via a real query
     * against the (membership, periodStart) unique constraint rather than
     * relying on catching a DB violation, so a same-day re-run never even
     * attempts the absent-marking step a second time.
     */
    private function generateOneCycle(Membership $membership): bool
    {
        $periodStart = $membership->getNextBillingDate();
        if ($periodStart === null) {
            return false;
        }

        if ($this->invoices->findOneForMembershipAndPeriodStart($membership, $periodStart) !== null) {
            return false;
        }

        $priorInvoice = $this->invoices->findMostRecentRecurringForMembership($membership);
        if ($priorInvoice !== null && $priorInvoice->getStatus() === InvoiceStatus::PENDING) {
            $priorInvoice->markAbsent();
        }

        $anchorDay = $membership->getBillingAnchorDay();
        $periodEnd = BillingCycleCalculator::advance($periodStart, $anchorDay)->modify('-1 day');

        $invoice = new Invoice($membership, $membership->getPlan()->getPrice(), $periodStart, $periodEnd, $periodStart);
        $this->em->persist($invoice);

        $membership->advanceNextBillingDate(BillingCycleCalculator::advance($periodStart, $anchorDay));

        $this->em->flush();

        return true;
    }
}

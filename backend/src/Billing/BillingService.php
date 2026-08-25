<?php

namespace App\Billing;

use App\Audit\AuditLogger;
use App\Entity\Invoice;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentMethod;
use App\Event\InvoiceMarkedPaidEvent;
use App\Referral\ReferralService;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.9: manual payment recording. Membership stays
 * unaware of this module — it only ever emits `membership.created`
 * (Phase 4, unmodified); MembershipInvoiceSubscriber is what connects the
 * two, keeping Billing decoupled the same way Notification is (CLAUDE.md
 * "Events" convention).
 */
class BillingService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly ReferralService $referrals,
    ) {
    }

    /**
     * functional requirements §8.1: "an invoice is created with status
     * pending." If the gym's Owner has an unapplied referral credit
     * (Phase 9.2, "refer a gym, both get a free month"), it's consumed
     * here — deterministically, against a real stored balance the Owner
     * already earned by redeeming a code, not an inferred/assumed
     * payment. Still recorded as a mark-paid, audit-logged like any other.
     */
    public function issueInvoiceForMembership(Membership $membership): Invoice
    {
        $periodStart = $periodEnd = $dueDate = null;
        if ($membership->getBillingAnchorDay() !== null) {
            // gym-management-billing-v1.md §5.1: the very first invoice
            // gets period fields too, so CheckInEligibilityChecker's
            // overdue rule applies to it the same as every generated one.
            $periodStart = $membership->getStartDate();
            $periodEnd = BillingCycleCalculator::advance($periodStart, $membership->getBillingAnchorDay())->modify('-1 day');
            $dueDate = $periodStart;
        }

        $invoice = new Invoice($membership, $membership->getPlan()->getPrice(), $periodStart, $periodEnd, $dueDate);
        $this->em->persist($invoice);
        $this->em->flush();

        $owner = $membership->getPlan()->getGym()->getOwner();
        if ($this->referrals->consumeCreditIfAvailable($owner) !== null) {
            $this->applyPayment($invoice, $owner, PaymentMethod::REFERRAL_CREDIT);
        }

        return $invoice;
    }

    /**
     * architecture doc §6.9 / §9.1 InvoiceVoter::MARK_PAID: Owner only,
     * no exceptions. Caller is responsible for the Voter check — this is
     * the state-transition layer underneath it, same split as
     * MembershipService's pause()/resume()/cancel().
     */
    public function markPaid(Invoice $invoice, User $owner, PaymentMethod $method): void
    {
        if ($invoice->getStatus() !== InvoiceStatus::PENDING) {
            throw new InvoiceConflictException('not_pending', 'Only a pending invoice can be marked paid.');
        }

        $this->applyPayment($invoice, $owner, $method);
    }

    /**
     * gym-management-billing-v1.md §5.2 — the recurring-billing payment
     * endpoint. Identical whether $invoice is PENDING or ABSENT (no
     * reopening step); only "already PAID" is rejected. Voter checks
     * (RECORD_PAYMENT, and RESET_BILLING_CYCLE when requested) are the
     * caller's responsibility — this is the state-transition layer
     * underneath, same split as markPaid()/pause()/resume().
     */
    public function recordRecurringPayment(
        Invoice $invoice,
        User $recordedBy,
        string $amount,
        PaymentMethod $method,
        bool $resetBillingCycle,
        ?string $note,
    ): Payment {
        if ($invoice->getStatus() === InvoiceStatus::PAID) {
            throw new InvoiceConflictException('already_paid', 'This invoice has already been paid.');
        }

        // bcmath isn't available in this environment (confirmed via
        // php -m) — normalized-string comparison instead, same
        // number_format((float) ..., 2, '.', '') pattern this codebase
        // already uses for money elsewhere (e.g. ExpenseController).
        if (number_format((float) $amount, 2, '.', '') !== number_format((float) $invoice->getAmount(), 2, '.', '')) {
            throw new PaymentAmountMismatchException();
        }

        $payment = new Payment($invoice, $amount, $method, $recordedBy, $resetBillingCycle, $note);
        $this->em->persist($payment);

        $this->applyPayment($invoice, $recordedBy, $method);

        if ($resetBillingCycle) {
            $membership = $invoice->getMembership();
            $paidAt = $payment->getPaidAt();
            $newAnchorDay = (int) $paidAt->format('j');
            $membership->resetBillingCycle($newAnchorDay, BillingCycleCalculator::advance($paidAt, $newAnchorDay));
            $this->em->flush();
        }

        return $payment;
    }

    /** @return Invoice[] */
    public function listAllForOwner(): array
    {
        return $this->invoices->findAllOrderedByIssuedAtDesc();
    }

    /** @return Invoice[] */
    public function listForMember(MemberProfile $member): array
    {
        return $this->invoices->findAllForMember($member);
    }

    /**
     * Shared by the Owner's explicit mark-paid action and the automatic
     * referral-credit path above — both are equally real "this invoice
     * is now paid" transitions, so both get the identical audit trail and
     * Member notification, per architecture doc §9's rule and functional
     * requirements §8.1's "the Member is notified."
     */
    private function applyPayment(Invoice $invoice, User $recordedBy, PaymentMethod $method): void
    {
        $invoice->markPaid($recordedBy, $method);
        $this->em->flush();

        $this->auditLogger->log($recordedBy, 'invoice.marked_paid', 'Invoice', $invoice->getId(), [
            'paymentMethod' => $method->value,
            'amount' => $invoice->getAmount(),
        ]);

        $this->dispatcher->dispatch(new InvoiceMarkedPaidEvent($invoice), InvoiceMarkedPaidEvent::NAME);
    }
}

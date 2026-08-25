<?php

namespace App\Security\Voter;

use App\Entity\Invoice;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * gym-management-billing-v1.md §5.3 (RESET_BILLING_CYCLE copied verbatim)
 * + §6's endpoint table (RECORD_PAYMENT: Owner, Staff branch-scoped).
 * Additive alongside the existing InvoiceVoter (VIEW/MARK_PAID, untouched)
 * — the two Voters serve two different flows on the same entity: the
 * one-time enrollment invoice (Owner-only, no exceptions, InvoiceVoter)
 * vs. recurring payment recording (Owner + branch-scoped Staff, here).
 * This is a deliberate, spec-explicit widening of Staff into a billing
 * action — narrower than "billing access" in general: Staff can record a
 * payment in their own branch, nothing else (no RESET_BILLING_CYCLE, no
 * view of the Owner-only one-time invoice list).
 */
final class InvoicePaymentVoter extends AppVoter
{
    const RECORD_PAYMENT = 'INVOICE_RECORD_PAYMENT';
    const RESET_BILLING_CYCLE = 'RESET_BILLING_CYCLE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::RECORD_PAYMENT, self::RESET_BILLING_CYCLE], true)
            && $subject instanceof Invoice;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::RESET_BILLING_CYCLE) {
            return $this->isOwner($user);
        }

        // RECORD_PAYMENT
        if ($this->isOwner($user)) {
            return true;
        }

        return $this->isStaff($user)
            && $this->hasAssignedBranch($user, $subject->getMembership()->getPlan()->getBranch());
    }
}

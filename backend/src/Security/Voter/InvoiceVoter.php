<?php

namespace App\Security\Voter;

use App\Entity\Invoice;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — Owner manages/marks paid, Member
 * views own only (§6.9).
 *
 * One adaptation beyond the literal copy, same reasoning as MemberVoter:
 * the doc's Owner branch reads
 * `$subject->getMembership()->getMember()->getUser()->getGym() === $user->getGym()`,
 * but this project (single-gym product, CLAUDE.md) never gave User a
 * getGym(). With exactly one gym in practice, "does this invoice belong
 * to the Owner's gym" collapses to "is this an Owner at all" — every
 * Invoice belongs to it.
 */
final class InvoiceVoter extends AppVoter
{
    const VIEW = 'INVOICE_VIEW';           // Owner: any in their gym; Member: own only
    const MARK_PAID = 'INVOICE_MARK_PAID'; // Owner only — a Member can never confirm their own payment

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MARK_PAID]) && $subject instanceof Invoice;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::MARK_PAID) {
            return $this->isOwner($user); // single-gym product — no exceptions, ever
        }

        // VIEW
        if ($this->isOwner($user)) {
            return true;
        }

        return $this->isMember($user) && $subject->getMembership()->getMember()->getUser() === $user;
    }
}

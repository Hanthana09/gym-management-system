<?php

namespace App\Security\Voter;

use App\Entity\Expense;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — record/view/manage operating
 * expenses (Phase 17). Owner: full CRUD, any branch. Staff: create +
 * view only, own assigned branch(es) — reuses `hasAssignedBranch()` from
 * Phase 16. Coach/Member: denied entirely.
 *
 * One adaptation beyond the literal copy, same reasoning as
 * InvoiceVoter/MemberVoter/AttendanceVoter elsewhere in this codebase:
 * the doc's Owner branch reads
 * `$subject->getBranch()->getGym()->getOwner() === $user`. Branch DOES
 * have a real `getGym()` here (unlike User, which never got one — see
 * MemberVoter's docblock) — but Owner-scoping still collapses to
 * `isOwner($user)` alone everywhere else in this single-gym product
 * (CLAUDE.md), same as InvoiceVoter/MemberVoter/AttendanceVoter::CHECK_IN
 * all do despite each having a real relation chain available too. Mirrors
 * the more common pattern in this codebase rather than BranchVoter's
 * literal getGym()->getOwner() check (which exists only because Branch
 * itself, not something branch-scoped, is BranchVoter's subject).
 */
final class ExpenseVoter extends AppVoter
{
    const CREATE = 'EXPENSE_CREATE'; // Owner: any branch; Staff: own assigned branch(es) only
    const VIEW = 'EXPENSE_VIEW';     // Owner: any branch; Staff: own assigned branch(es) only
    const MANAGE = 'EXPENSE_MANAGE'; // update/delete — Owner only, no exceptions

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::CREATE, self::VIEW, self::MANAGE], true)
            && $subject instanceof Expense;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($this->isOwner($user)) {
            return true; // single-gym product — full CRUD, every branch is this Owner's
        }

        if ($this->isStaff($user) && in_array($attribute, [self::CREATE, self::VIEW], true)) {
            return $this->hasAssignedBranch($user, $subject->getBranch()); // create + read only
        }

        return false; // Coach and Member: denied entirely
    }
}

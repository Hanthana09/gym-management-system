<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — add/remove/suspend Staff role
 * accounts (Owner only, §2 row 3, new in Phase 15 — mirrors
 * CoachManagementVoter's structure, which doesn't exist in this codebase
 * yet; see this Voter's own note in the Phase 15 summary for why that's
 * not a blocker here).
 *
 * One adaptation beyond the literal copy: the doc's body reads
 * `$subject->getGym() === $user->getGym()`, but this project (single-gym
 * product, CLAUDE.md) never gave User a getGym() — same collapse already
 * used by MemberVoter/AttendanceVoter's Owner/Staff branches. The subject
 * here is a `User` directly (role staff), not a dedicated profile entity
 * — Staff has no STAFF_PROFILE table (architecture doc §5.2).
 */
final class StaffManagementVoter extends AppVoter
{
    const MANAGE = 'STAFF_ACCOUNT_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof User && $subject->getRole() === UserRole::STAFF;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user); // single-gym product — every Staff account belongs to this Owner's gym
    }
}

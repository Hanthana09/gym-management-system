<?php

namespace App\Security\Voter;

use App\Entity\MemberProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — updated in Phase 15 to grant Staff
 * `CHECK_IN` (matching Owner's existing front-desk capability) and
 * gym-scoped read-only `VIEW`; `VIEW_ALL` (reports/dashboard) stays
 * Owner-only, Staff explicitly excluded per §2's "no reports" rule.
 *
 * One adaptation beyond the literal copy: the doc's Staff VIEW branch
 * reads `$subject->getUser()->getGym() === $user->getGym()`, but this
 * project (single-gym product, CLAUDE.md) never gave User a getGym() —
 * same collapse already used by MemberVoter's Owner/Staff branches.
 */
final class AttendanceVoter extends AppVoter
{
    const CHECK_IN = 'ATTENDANCE_CHECK_IN'; // self, or Owner/Staff on behalf of a member (front desk)
    const VIEW = 'ATTENDANCE_VIEW';
    const VIEW_ALL = 'ATTENDANCE_VIEW_ALL'; // Owner dashboard / reports

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::CHECK_IN, self::VIEW, self::VIEW_ALL]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::VIEW_ALL) {
            return $this->isOwner($user); // Staff explicitly excluded — §2's "no reports" rule
        }
        if ($attribute === self::CHECK_IN) {
            return $this->isOwner($user)
                || $this->isStaff($user)
                || ($subject instanceof MemberProfile && $subject->getUser() === $user);
        }
        // VIEW: Coach sees own clients, Staff sees any (read-only, gym-scoped), Member sees self
        if ($this->isCoach($user)) {
            return $subject instanceof MemberProfile && $subject->hasCoach($user);
        }
        if ($this->isStaff($user)) {
            return $subject instanceof MemberProfile; // single-gym product — same collapse as MemberVoter's Staff branch
        }

        return $subject instanceof MemberProfile && $subject->getUser() === $user;
    }
}

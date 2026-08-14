<?php

namespace App\Security\Voter;

use App\Entity\MemberProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — add/suspend/remove members; view
 * member records (§2 rows 4, 5 — Staff granted read-only VIEW as of
 * Phase 15).
 *
 * One adaptation beyond the literal copy: the doc's Owner/Staff branches
 * read `$subject->getUser()->getGym() === $user->getGym()`, but this
 * project (single-gym product, CLAUDE.md) never gave User a getGym() —
 * no entity here has one (see AttendanceLogRepository's and
 * InvitationRepository::findApprovedUsersForGym()'s comments for the
 * same reasoning elsewhere). With exactly one gym in practice, "does this
 * member belong to the gym" collapses to "is this an Owner, or Staff
 * asking to VIEW" — every MemberProfile belongs to it.
 */
final class MemberVoter extends AppVoter
{
    const MANAGE = 'MEMBER_MANAGE';   // add / suspend / remove — Owner only
    const VIEW = 'MEMBER_VIEW';     // Owner: any; Coach: own clients; Staff: any (read-only); Member: self

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::VIEW])
            && $subject instanceof MemberProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($this->isOwner($user)) {
            return true; // single-gym product — every MemberProfile is this Owner's
        }

        if ($attribute === self::VIEW && $this->isCoach($user)) {
            return $subject->hasCoach($user); // "own clients only"
        }

        // Staff (Phase 15): read-only, gym-scoped — same visibility as
        // Owner, but only ever VIEW, never MANAGE. Deliberately no
        // isStaff branch under MANAGE above — if that ever needs to
        // change, it's a §2 table edit first, not a quiet addition here.
        if ($attribute === self::VIEW && $this->isStaff($user)) {
            return true; // single-gym product — same collapse as the Owner branch
        }

        if ($attribute === self::VIEW && $this->isMember($user)) {
            return $subject->getUser() === $user; // "own record only"
        }

        return false;
    }
}

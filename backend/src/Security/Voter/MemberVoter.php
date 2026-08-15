<?php

namespace App\Security\Voter;

use App\Entity\MemberProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — add/suspend/remove members; view
 * member records (§2 rows 4, 5 — Staff's VIEW scope narrows to branch
 * assignment as of Phase 16, was gym-wide as of Phase 15).
 *
 * One adaptation beyond the literal copy: the doc's Owner branch reads
 * `$subject->getUser()->getGym() === $user->getGym()`, but this project
 * (single-gym product, CLAUDE.md) never gave User a getGym() — no entity
 * here has one (see AttendanceLogRepository's and
 * InvitationRepository::findApprovedUsersForGym()'s comments for the
 * same reasoning elsewhere). With exactly one gym in practice, "does this
 * member belong to the gym" collapses to "is this an Owner" — every
 * MemberProfile belongs to it. Staff's branch, unlike Owner's, is a real
 * per-user check now (hasAssignedBranch()), not a collapse — that's the
 * actual point of this phase.
 */
final class MemberVoter extends AppVoter
{
    const MANAGE = 'MEMBER_MANAGE';   // add / suspend / remove — Owner only
    const VIEW = 'MEMBER_VIEW';     // Owner: any; Coach: own clients; Staff: own branch(es) only; Member: self

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

        // Staff (Phase 16 update): scoped to the member's ENROLLING branch — the branch
        // their MEMBERSHIP_PLAN belongs to — not gym-wide as it was in Phase 15.
        // This does NOT restrict who Staff can check in (see AttendanceVoter::CHECK_IN,
        // which stays permissive per the hub model) — it only scopes the browsable
        // member list to "people who enrolled where I work," a UI convenience,
        // not a security boundary on attendance itself.
        if ($attribute === self::VIEW && $this->isStaff($user)) {
            $enrollingBranch = $subject->getActiveMembership()?->getPlan()?->getBranch();

            return $enrollingBranch !== null && $this->hasAssignedBranch($user, $enrollingBranch);
        }

        if ($attribute === self::VIEW && $this->isMember($user)) {
            return $subject->getUser() === $user; // "own record only"
        }

        return false;
    }
}

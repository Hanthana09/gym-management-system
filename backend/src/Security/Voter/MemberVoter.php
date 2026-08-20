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
 *
 * gym-management-member-profile-extension.md §6.1/§9.1: MemberProfile
 * now carries a real `gym` (added specifically so this Voter can enforce
 * a genuine cross-gym boundary instead of the collapse above). Both
 * isOwner() branches below check `$subject->getGym()->getOwner() ===
 * $user`, but only when a gym is actually set — a still-null gym (the
 * pre-backfill window right after this phase's migration runs, before
 * app:member:backfill-ids has processed that row) falls back to the old
 * collapse so existing Owner access to not-yet-migrated rows doesn't
 * regress.
 *
 * Follow-up feature (editable/manual Member ID mode) adds EDIT_PROFILE,
 * deliberately separate from MANAGE: creation + profile data entry
 * (dob, gender, address fields, memberId when the gym is in manual mode)
 * widen to Owner+Staff, gym-wide/unscoped for Staff (no branch context
 * exists for a freshly walk-in-created member — it has no membership
 * yet), but MANAGE (suspend/reactivate an existing account) stays
 * Owner-only, untouched — the widening is scoped to what was actually
 * asked for, not a blanket Staff-gets-MANAGE-too change.
 */
final class MemberVoter extends AppVoter
{
    const MANAGE = 'MEMBER_MANAGE';   // add / suspend / remove — Owner only
    const VIEW = 'MEMBER_VIEW';     // Owner: any; Coach: own clients; Staff: own branch(es) only; Member: self
    const EDIT_PROFILE = 'MEMBER_EDIT_PROFILE'; // create + dob/gender/address*/memberId — Owner: own gym; Staff: gym-wide, unscoped

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::VIEW, self::EDIT_PROFILE])
            && $subject instanceof MemberProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($this->isOwner($user)) {
            $gym = $subject->getGym();

            // null gym = not yet backfilled (gym-management-member-profile-extension.md) —
            // stays visible to any Owner, same as the pre-this-phase collapse.
            return $gym === null || $gym->getOwner() === $user;
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

        if ($attribute === self::EDIT_PROFILE && $this->isStaff($user)) {
            return true; // gym-wide, unscoped — see this class's docblock
        }

        return false;
    }
}

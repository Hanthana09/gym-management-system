<?php

namespace App\Security\Voter;

use App\Entity\AttendanceLog;
use App\Entity\MemberProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1, updated for roadmap Phase 16 —
 * `CHECK_IN` stays hub-permissive (deliberately no branch check on WHICH
 * member: any Member can be checked in by Owner/Staff regardless of
 * their enrolling branch; the branch this check-in is recorded under is
 * a controller-level concern, see AttendanceController's own docblock).
 * `VIEW` (existing ATTENDANCE_LOG rows) now takes an AttendanceLog
 * subject — not MemberProfile, per §9.1's updated body — and Staff's
 * case narrows to `hasAssignedBranch()`, a direct check since
 * ATTENDANCE_LOG carries its own branch_id, no indirection needed.
 * `VIEW_ALL` (reports/dashboard) is unchanged: Owner-only, Staff
 * explicitly excluded per §2's "no reports" rule.
 */
final class AttendanceVoter extends AppVoter
{
    const CHECK_IN = 'ATTENDANCE_CHECK_IN'; // self, or Owner/Staff on behalf of a member (front desk) — any member, any branch
    // Check-in-timer feature's minimal check-out mutation: self only, deliberately
    // narrower than CHECK_IN — there's no front-desk checkout variant (Owner/Staff
    // acting on a member's behalf), so unlike CHECK_IN this doesn't grant those roles.
    const CHECK_OUT = 'ATTENDANCE_CHECK_OUT';
    const VIEW = 'ATTENDANCE_VIEW';
    const VIEW_ALL = 'ATTENDANCE_VIEW_ALL'; // Owner dashboard / reports

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::VIEW_ALL) {
            return true; // no subject — same as before Phase 16
        }
        if ($attribute === self::CHECK_IN || $attribute === self::CHECK_OUT) {
            return true; // subject is a MemberProfile, checked below
        }

        return $attribute === self::VIEW && $subject instanceof AttendanceLog;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::VIEW_ALL) {
            return $this->isOwner($user); // Staff explicitly excluded — §2's "no reports" rule
        }
        if ($attribute === self::CHECK_IN) {
            // Deliberately permissive on WHICH member: any Member can be checked in by
            // Owner/Staff regardless of that member's enrolling branch — this is the
            // hub model in practice. The branch this check-in is recorded under comes
            // from where Owner/Staff is physically working, not a restriction on the member.
            return $this->isOwner($user)
                || $this->isStaff($user)
                || ($subject instanceof MemberProfile && $subject->getUser() === $user);
        }
        if ($attribute === self::CHECK_OUT) {
            return $subject instanceof MemberProfile && $subject->getUser() === $user;
        }
        // VIEW (viewing existing ATTENDANCE_LOG rows, not the check-in action itself):
        if ($this->isCoach($user)) {
            return $subject instanceof AttendanceLog && $subject->getMember()->hasCoach($user);
        }
        if ($this->isStaff($user)) {
            // Direct check — ATTENDANCE_LOG carries its own branch_id, no indirection needed.
            return $subject instanceof AttendanceLog && $this->hasAssignedBranch($user, $subject->getBranch());
        }

        return $subject instanceof AttendanceLog && $subject->getMember()->getUser() === $user;
    }
}

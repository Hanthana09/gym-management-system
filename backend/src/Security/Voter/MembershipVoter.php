<?php

namespace App\Security\Voter;

use App\Entity\Membership;
use App\Entity\MembershipPlan;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Not written out in architecture doc §9.1 — built following MemberVoter's
 * pattern (§9.1: MANAGE is Owner-only, gym-scoped; other attributes are
 * per-role ownership checks), the same structural shape used by every
 * other Voter in that section.
 *
 * BILLING_MANAGE/BILLING_VIEW added by gym-management-billing-v1.md §6's
 * endpoint table — suspend/reactivate and the billing-status read.
 * Deliberately separate from MANAGE (which stays Owner-only, unscoped —
 * plan/enrollment CRUD is untouched) and from VIEW/RESPOND (which stay
 * Member-self-only, untouched): these two are explicit, narrow grants —
 * Staff gets branch-scoped suspend/reactivate/billing-read, Coach gets
 * read-only billing status for their own clients — nothing wider.
 */
final class MembershipVoter extends AppVoter
{
    const MANAGE = 'MEMBERSHIP_MANAGE';   // Owner — plans & memberships, own gym only (CRUD, enrollment)
    const VIEW = 'MEMBERSHIP_VIEW';       // Member — own membership only (read)
    const RESPOND = 'MEMBERSHIP_RESPOND'; // Member — own membership only (pause/resume/cancel)
    const BILLING_MANAGE = 'MEMBERSHIP_BILLING_MANAGE'; // suspend/reactivate — Owner: any; Staff: own assigned branch(es)
    const BILLING_VIEW = 'MEMBERSHIP_BILLING_VIEW';     // billing-status read — Owner: any; Staff: own branch; Coach: own clients; Member: self

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::VIEW, self::RESPOND, self::BILLING_MANAGE, self::BILLING_VIEW], true)
            && ($subject instanceof MembershipPlan || $subject instanceof Membership);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::MANAGE) {
            if (!$this->isOwner($user)) {
                return false;
            }

            $gym = $subject instanceof MembershipPlan ? $subject->getGym() : $subject->getPlan()->getGym();

            return $gym->getOwner() === $user;
        }

        if (in_array($attribute, [self::BILLING_MANAGE, self::BILLING_VIEW], true) && $subject instanceof Membership) {
            if ($this->isOwner($user)) {
                return true; // single-gym product — no exceptions, ever
            }

            if ($this->isStaff($user)) {
                return $this->hasAssignedBranch($user, $subject->getPlan()->getBranch());
            }

            if ($attribute !== self::BILLING_VIEW) {
                return false; // BILLING_MANAGE: Owner/Staff only — Coach and Member never suspend/reactivate
            }

            if ($this->isCoach($user)) {
                return $subject->getMember()->hasCoach($user);
            }

            return $this->isMember($user) && $subject->getMember()->getUser() === $user;
        }

        // VIEW / RESPOND only ever apply to a Membership, and only to the
        // Member it belongs to — an Owner or Coach gets neither, same as
        // MemberVoter::VIEW denies anyone who isn't Owner/Coach/self.
        return $subject instanceof Membership
            && $this->isMember($user)
            && $subject->getMember()->getUser() === $user;
    }
}

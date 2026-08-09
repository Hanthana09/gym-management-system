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
 */
final class MembershipVoter extends AppVoter
{
    const MANAGE = 'MEMBERSHIP_MANAGE';   // Owner — plans & memberships, own gym only (CRUD, enrollment)
    const VIEW = 'MEMBERSHIP_VIEW';       // Member — own membership only (read)
    const RESPOND = 'MEMBERSHIP_RESPOND'; // Member — own membership only (pause/resume/cancel)

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::VIEW, self::RESPOND], true)
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

        // VIEW / RESPOND only ever apply to a Membership, and only to the
        // Member it belongs to — an Owner or Coach gets neither, same as
        // MemberVoter::VIEW denies anyone who isn't Owner/Coach/self.
        return $subject instanceof Membership
            && $this->isMember($user)
            && $subject->getMember()->getUser() === $user;
    }
}

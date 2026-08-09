<?php

namespace App\Security\Voter;

use App\Entity\MemberProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied verbatim from architecture doc §9.1 — "already written in full,
 * don't rewrite it." No adaptation needed: AppVoter's isOwner/isCoach
 * helpers and MemberProfile::getUser()/hasCoach() already exist with
 * matching signatures.
 */
final class AttendanceVoter extends AppVoter
{
    const CHECK_IN = 'ATTENDANCE_CHECK_IN'; // self, or Owner on behalf of a member (front desk)
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
            return $this->isOwner($user);
        }
        if ($attribute === self::CHECK_IN) {
            return $this->isOwner($user) || ($subject instanceof MemberProfile && $subject->getUser() === $user);
        }
        // VIEW: Coach sees own clients, Member sees self, Owner covered by VIEW_ALL above
        if ($this->isCoach($user)) {
            return $subject instanceof MemberProfile && $subject->hasCoach($user);
        }

        return $subject instanceof MemberProfile && $subject->getUser() === $user;
    }
}

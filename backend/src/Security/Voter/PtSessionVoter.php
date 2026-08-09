<?php

namespace App\Security\Voter;

use App\Entity\PtSession;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied verbatim from architecture doc §9.1 — "already written in full,
 * don't rewrite it." No adaptation needed: AppVoter's isOwner/isCoach/
 * isMember helpers and PtSession::getCoach()/getMember() already exist
 * with matching signatures.
 */
final class PtSessionVoter extends AppVoter
{
    const REQUEST = 'PT_SESSION_REQUEST';  // Member, for self
    const RESPOND = 'PT_SESSION_RESPOND';  // Coach, own sessions only
    const VIEW = 'PT_SESSION_VIEW';     // Owner: any; Coach/Member: own

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::REQUEST, self::RESPOND, self::VIEW])
            && $subject instanceof PtSession;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return match ($attribute) {
            self::REQUEST => $this->isMember($user) && $subject->getMember()->getUser() === $user,
            self::RESPOND => $this->isCoach($user) && $subject->getCoach()->getUser() === $user,
            self::VIEW => $this->isOwner($user)
                || ($this->isCoach($user) && $subject->getCoach()->getUser() === $user)
                || ($this->isMember($user) && $subject->getMember()->getUser() === $user),
            default => false,
        };
    }
}

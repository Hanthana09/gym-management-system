<?php

namespace App\Security\Voter;

use App\Entity\PtSession;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1, updated for roadmap Phase 16 —
 * `RESPOND` now also confirms the Coach is assigned to the session's
 * branch. Branch check added here, not removed from anywhere — a Coach
 * responding to a session assumes they're actually assigned to work at
 * that branch. If a session somehow references a branch the Coach isn't
 * assigned to (shouldn't happen if request-creation validates this —
 * PtSessionController::create() does), reject it here too.
 */
final class PtSessionVoter extends AppVoter
{
    const REQUEST = 'PT_SESSION_REQUEST';  // Member, for self
    const RESPOND = 'PT_SESSION_RESPOND';  // Coach, own sessions AND own assigned branch only
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
            self::RESPOND => $this->isCoach($user)
                && $subject->getCoach()->getUser() === $user
                && $this->hasAssignedBranch($user, $subject->getBranch()),
            self::VIEW => $this->isOwner($user)
                || ($this->isCoach($user) && $subject->getCoach()->getUser() === $user)
                || ($this->isMember($user) && $subject->getMember()->getUser() === $user),
            default => false,
        };
    }
}

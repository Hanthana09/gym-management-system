<?php

namespace App\Security\Voter;

use App\Entity\BodyMetric;
use App\Entity\WorkoutLog;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied verbatim from architecture doc §9.1 — "already written in full,
 * don't rewrite it." No Coach or Owner branch, deliberately: this is the
 * open decision flagged repeatedly in architecture doc §9 ("should
 * Coaches see any of their clients' WORKOUT_LOG/BODY_METRIC data?") and
 * again in the Phase 8 task prompt. Do not add one without confirming
 * first — if a per-client opt-in ever ships, the addition point is
 * already commented below, exactly as the architecture doc left it.
 */
final class PersonalTrackingVoter extends AppVoter
{
    const MANAGE = 'TRACKING_MANAGE'; // WorkoutLog or BodyMetric

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE
            && ($subject instanceof WorkoutLog || $subject instanceof BodyMetric);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        // No Owner/Coach branch on purpose — see the open decision above.
        // If per-client opt-in ships, add: || ($this->isCoach($user) && $subject->getMember()->hasGrantedCoachAccess($user))
        return $this->isMember($user) && $subject->getMember()->getUser() === $user;
    }
}

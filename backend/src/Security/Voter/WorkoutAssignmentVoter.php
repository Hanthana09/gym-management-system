<?php

namespace App\Security\Voter;

use App\Entity\WorkoutAssignment;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * setly-phase-workout-scheduling.md §7: VIEW covers the assignment's own
 * detail, its scoped exercise list, and its log history — Coach who owns
 * it (via the denormalized `coach` field) or the Member it belongs to.
 * Assign-time authorization (is this coach allowed to assign this schedule
 * to this member) lives in WorkoutAssignmentController, checked against
 * WorkoutScheduleVoter::MANAGE on the schedule itself plus a gym-scoping
 * check — not here, since no WorkoutAssignment exists yet at that point.
 */
final class WorkoutAssignmentVoter extends AppVoter
{
    const VIEW = 'WORKOUT_ASSIGNMENT_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof WorkoutAssignment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user)
            || ($this->isCoach($user) && $subject->getCoach() === $user)
            || ($this->isMember($user) && $subject->getMember() === $user);
    }
}

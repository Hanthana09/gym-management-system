<?php

namespace App\Security\Voter;

use App\Entity\WorkoutSchedule;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * setly-phase-workout-scheduling.md §2.1: a schedule is authored once by a
 * Coach — MANAGE covers create/edit/delete of the template and its line
 * items. VIEW additionally grants the Owner (single-gym product, same
 * "Owner sees everything" collapse ExpenseVoter/ExerciseVoter use)
 * visibility without edit rights.
 */
final class WorkoutScheduleVoter extends AppVoter
{
    const VIEW = 'WORKOUT_SCHEDULE_VIEW';
    const MANAGE = 'WORKOUT_SCHEDULE_MANAGE'; // create/edit/delete schedule + line items — owning Coach only

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE], true)
            && $subject instanceof WorkoutSchedule;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return match ($attribute) {
            self::VIEW => $this->isOwner($user) || ($this->isCoach($user) && $subject->getCoach() === $user),
            self::MANAGE => $this->isCoach($user) && $subject->getCoach() === $user,
            default => false,
        };
    }
}

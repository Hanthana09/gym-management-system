<?php

namespace App\Security\Voter;

use App\Entity\ExerciseLog;
use App\Repository\WorkoutScheduleExerciseRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * setly-phase-workout-scheduling.md §5, implemented exactly as written
 * there:
 *
 *   1. assignment = subject.assignment
 *   2. if assignment.member_id !== currentUser.id -> DENY
 *   3. if assignment.status !== 'active' -> DENY
 *   4. exists = WorkoutScheduleExercise::query()
 *        .where('schedule_id', assignment.schedule_id)
 *        .where('exercise_id', subject.exercise_id)
 *        .exists()
 *   5. if !exists -> DENY
 *   6. GRANT
 *
 * Single indexed lookup (schedule_id + exercise_id composite index on
 * WorkoutScheduleExercise) — this check reads live data, so a coach
 * removing an exercise mid-session immediately blocks further logging of
 * it, no cache invalidation to get wrong.
 */
final class ExerciseLogVoter extends AppVoter
{
    const CREATE = 'EXERCISE_LOG_CREATE';

    public function __construct(private readonly WorkoutScheduleExerciseRepository $scheduleExercises)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::CREATE && $subject instanceof ExerciseLog;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $assignment = $subject->getAssignment();

        if ($assignment->getMember() !== $user) {
            return false;
        }

        if (!$assignment->isActive()) {
            return false;
        }

        return $this->scheduleExercises->existsForScheduleAndExercise($assignment->getSchedule(), $subject->getExercise());
    }
}

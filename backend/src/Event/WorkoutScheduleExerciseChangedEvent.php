<?php

namespace App\Event;

use App\Entity\WorkoutSchedule;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * setly-phase-workout-scheduling.md §6: fired on WorkoutScheduleExercise
 * create/update/delete. Carries the parent schedule (not the line item —
 * on delete the line item is already gone) plus a changeType the
 * Mercure publisher forwards verbatim in its thin payload.
 */
final class WorkoutScheduleExerciseChangedEvent extends Event
{
    public const NAME = 'workout_schedule_exercise.changed';

    public const CHANGE_CREATED = 'created';
    public const CHANGE_UPDATED = 'updated';
    public const CHANGE_DELETED = 'deleted';

    public function __construct(
        private readonly WorkoutSchedule $schedule,
        private readonly string $changeType,
    ) {
    }

    public function getSchedule(): WorkoutSchedule
    {
        return $this->schedule;
    }

    public function getChangeType(): string
    {
        return $this->changeType;
    }
}

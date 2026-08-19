<?php

namespace App\Event;

use App\Entity\WorkoutAssignment;
use Symfony\Contracts\EventDispatcher\Event;

/** setly-phase-workout-scheduling.md §4 step 6 / §6: fires the new assignment's Mercure publish. */
final class WorkoutAssignmentCreatedEvent extends Event
{
    public const NAME = 'workout_assignment.created';

    public function __construct(private readonly WorkoutAssignment $assignment)
    {
    }

    public function getAssignment(): WorkoutAssignment
    {
        return $this->assignment;
    }
}

<?php

namespace App\Event;

use App\Entity\WorkoutAssignment;
use Symfony\Contracts\EventDispatcher\Event;

/** setly-phase-workout-scheduling.md §4 step 3 / §6: fires the replaced (old) assignment's Mercure publish, so the member's app refetches away from a now-stale schedule. */
final class WorkoutAssignmentReplacedEvent extends Event
{
    public const NAME = 'workout_assignment.replaced';

    public function __construct(private readonly WorkoutAssignment $assignment)
    {
    }

    public function getAssignment(): WorkoutAssignment
    {
        return $this->assignment;
    }
}

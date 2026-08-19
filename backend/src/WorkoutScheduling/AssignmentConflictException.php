<?php

namespace App\WorkoutScheduling;

/**
 * setly-phase-workout-scheduling.md §4: "even if two requests race, the DB
 * rejects the second `active` row for the same coach-member pair, and the
 * service catches that as a conflict rather than relying on the
 * transaction alone." Mirrors PtSessionConflictException's shape.
 */
class AssignmentConflictException extends \RuntimeException
{
    public function __construct(string $message = 'This member already has an active assignment from you being created concurrently.')
    {
        parent::__construct($message);
    }
}

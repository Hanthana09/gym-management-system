<?php

namespace App\WorkoutScheduling;

use App\Entity\Exercise;
use App\Entity\ExerciseLog;
use App\Entity\WorkoutAssignment;
use Doctrine\ORM\EntityManagerInterface;

/** setly-phase-workout-scheduling.md §3/§5: POST /exercise-logs, Voter-checked by the controller before this is ever called. */
class ExerciseLogService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(WorkoutAssignment $assignment, Exercise $exercise, int $setsCompleted, int $repsCompleted, ?string $weight, ?string $notes): ExerciseLog
    {
        $log = new ExerciseLog($assignment, $exercise, $assignment->getMember(), $setsCompleted, $repsCompleted, $weight, $notes);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }
}

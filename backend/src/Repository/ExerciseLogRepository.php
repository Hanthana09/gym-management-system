<?php

namespace App\Repository;

use App\Entity\ExerciseLog;
use App\Entity\WorkoutAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciseLog>
 */
class ExerciseLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseLog::class);
    }

    /**
     * GET /workout-assignments/{id}/logs — history for that assignment,
     * including a replaced assignment's own logs (setly-phase-workout-
     * scheduling.md §4 step 3: "its ExerciseLog rows remain queryable").
     *
     * @return ExerciseLog[]
     */
    public function findByAssignment(WorkoutAssignment $assignment): array
    {
        return $this->findBy(['assignment' => $assignment], ['loggedAt' => 'DESC']);
    }
}

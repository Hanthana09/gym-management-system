<?php

namespace App\Repository;

use App\Entity\Exercise;
use App\Entity\WorkoutSchedule;
use App\Entity\WorkoutScheduleExercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkoutScheduleExercise>
 */
class WorkoutScheduleExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkoutScheduleExercise::class);
    }

    /** @return WorkoutScheduleExercise[] */
    public function findBySchedule(WorkoutSchedule $schedule): array
    {
        return $this->createQueryBuilder('se')
            ->andWhere('se.schedule = :schedule')
            ->setParameter('schedule', $schedule)
            ->orderBy('se.dayNumber', 'ASC')
            ->addOrderBy('se.order', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * ExerciseLogVoter (setly-phase-workout-scheduling.md §5 step 4): the
     * single indexed existence check that scopes logging to the
     * assignment's schedule — never the global catalog.
     */
    public function existsForScheduleAndExercise(WorkoutSchedule $schedule, Exercise $exercise): bool
    {
        $count = $this->createQueryBuilder('se')
            ->select('COUNT(se.id)')
            ->andWhere('se.schedule = :schedule')
            ->andWhere('se.exercise = :exercise')
            ->setParameter('schedule', $schedule)
            ->setParameter('exercise', $exercise)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    /**
     * Member-facing scoped picker (GET /workout-assignments/{id}/exercises)
     * — joined to Exercise, filterable by muscle/equipment, never touching
     * the unscoped catalog. `muscle` checks both primary and secondary
     * muscles via the same JSONB_EXISTS DQL function
     * ExerciseRepository::findFilteredIds() uses (src/Doctrine/
     * JsonbExistsFunction.php) — Exercise's muscles moved from a single
     * `muscleGroup` string to `primaryMuscles`/`secondaryMuscles` JSON
     * arrays in the exercise-media phase.
     *
     * @return WorkoutScheduleExercise[]
     */
    public function findByScheduleWithFilters(WorkoutSchedule $schedule, ?string $muscle, ?string $equipment): array
    {
        $qb = $this->createQueryBuilder('se')
            ->innerJoin('se.exercise', 'ex')
            ->addSelect('ex')
            ->andWhere('se.schedule = :schedule')
            ->setParameter('schedule', $schedule)
            ->orderBy('se.dayNumber', 'ASC')
            ->addOrderBy('se.order', 'ASC');

        if ($muscle !== null && $muscle !== '') {
            $qb->andWhere('JSONB_EXISTS(ex.primaryMuscles, :muscle) = true OR JSONB_EXISTS(ex.secondaryMuscles, :muscle) = true')
                ->setParameter('muscle', $muscle);
        }
        if ($equipment !== null && $equipment !== '') {
            $qb->andWhere('ex.equipment = :equipment')->setParameter('equipment', $equipment);
        }

        return $qb->getQuery()->getResult();
    }
}

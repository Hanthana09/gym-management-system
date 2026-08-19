<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\WorkoutScheduleExerciseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * setly-phase-workout-scheduling.md §3: the template's content (a pivot
 * between WorkoutSchedule and Exercise). Cascade-deletes with its parent
 * schedule. Composite index on (schedule_id, exercise_id) per §5 —
 * ExerciseLogVoter's single-lookup existence check relies on it.
 *
 * #[ApiResource(operations: []) — real CRUD lives in
 * WorkoutScheduleController, same reasoning as WorkoutSchedule itself.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: WorkoutScheduleExerciseRepository::class)]
#[ORM\Index(columns: ['schedule_id', 'exercise_id'], name: 'workout_schedule_exercise_schedule_exercise_idx')]
class WorkoutScheduleExercise
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: WorkoutSchedule::class)]
    #[ORM\JoinColumn(name: 'schedule_id', nullable: false, onDelete: 'CASCADE')]
    private WorkoutSchedule $schedule;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: false)]
    private Exercise $exercise;

    #[ORM\Column]
    private int $dayNumber;

    #[ORM\Column(name: '`order`')]
    private int $order;

    #[ORM\Column]
    private int $sets;

    #[ORM\Column]
    private int $reps;

    #[ORM\Column(nullable: true)]
    private ?int $restSeconds = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(
        WorkoutSchedule $schedule,
        Exercise $exercise,
        int $dayNumber,
        int $order,
        int $sets,
        int $reps,
        ?int $restSeconds,
        ?string $notes,
    ) {
        $this->id = Uuid::v7();
        $this->schedule = $schedule;
        $this->exercise = $exercise;
        $this->dayNumber = $dayNumber;
        $this->order = $order;
        $this->sets = $sets;
        $this->reps = $reps;
        $this->restSeconds = $restSeconds;
        $this->notes = $notes;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSchedule(): WorkoutSchedule
    {
        return $this->schedule;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getDayNumber(): int
    {
        return $this->dayNumber;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function getSets(): int
    {
        return $this->sets;
    }

    public function getReps(): int
    {
        return $this->reps;
    }

    public function getRestSeconds(): ?int
    {
        return $this->restSeconds;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function update(int $dayNumber, int $order, int $sets, int $reps, ?int $restSeconds, ?string $notes): void
    {
        $this->dayNumber = $dayNumber;
        $this->order = $order;
        $this->sets = $sets;
        $this->reps = $reps;
        $this->restSeconds = $restSeconds;
        $this->notes = $notes;
    }
}

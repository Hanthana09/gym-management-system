<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ExerciseLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * setly-phase-workout-scheduling.md §3: a member's logged set, scoped by
 * `assignment_id` (never the global catalog). `member` is denormalized
 * from `assignment.member` at write time so ExerciseLogVoter stays a
 * single indexed lookup (§6's "Denormalized fields" note).
 *
 * #[ApiResource(operations: []) — real CRUD lives in ExerciseLogController,
 * gated by ExerciseLogVoter's CREATE check (§5) — a declarative write
 * operation can't express that Voter's live existence-query step.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ExerciseLogRepository::class)]
class ExerciseLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: WorkoutAssignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false)]
    private WorkoutAssignment $assignment;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: false)]
    private Exercise $exercise;

    /** Denormalized from `assignment.member` — see class docblock. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'member_id', nullable: false)]
    private User $member;

    #[ORM\Column]
    private \DateTimeImmutable $loggedAt;

    #[ORM\Column]
    private int $setsCompleted;

    #[ORM\Column]
    private int $repsCompleted;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $weight = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(
        WorkoutAssignment $assignment,
        Exercise $exercise,
        User $member,
        int $setsCompleted,
        int $repsCompleted,
        ?string $weight,
        ?string $notes,
    ) {
        $this->id = Uuid::v7();
        $this->assignment = $assignment;
        $this->exercise = $exercise;
        $this->member = $member;
        $this->loggedAt = new \DateTimeImmutable();
        $this->setsCompleted = $setsCompleted;
        $this->repsCompleted = $repsCompleted;
        $this->weight = $weight;
        $this->notes = $notes;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAssignment(): WorkoutAssignment
    {
        return $this->assignment;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getMember(): User
    {
        return $this->member;
    }

    public function getLoggedAt(): \DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function getSetsCompleted(): int
    {
        return $this->setsCompleted;
    }

    public function getRepsCompleted(): int
    {
        return $this->repsCompleted;
    }

    public function getWeight(): ?string
    {
        return $this->weight;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }
}

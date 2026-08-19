<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\WorkoutAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * setly-phase-workout-scheduling.md §3/§4: the coach→member link. `coach`
 * is denormalized (not derived via `schedule.coach`) so ExerciseLogVoter
 * and this entity's own Voter stay single-lookup fast (§6 of the phase
 * doc's "Denormalized fields" note).
 *
 * The `(coach_id, member_id) WHERE status = 'active'` partial unique index
 * (§3's real safety net) is raw SQL in the migration — Doctrine's
 * migration DSL has no partial-index construct, and every other unique
 * index in this codebase is already hand-written `addSql()` (see
 * Version20260814154702.php), so this isn't a special case.
 *
 * #[ApiResource(operations: []) — real CRUD lives in
 * WorkoutAssignmentController: the assign/replace transaction (§4) and the
 * 409-on-conflict path both need a service layer beyond what a declarative
 * write operation can express, same "custom write processor confirmed
 * unsafe" reasoning as everywhere else in this codebase.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: WorkoutAssignmentRepository::class)]
class WorkoutAssignment
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: WorkoutSchedule::class)]
    #[ORM\JoinColumn(name: 'schedule_id', nullable: false)]
    private WorkoutSchedule $schedule;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'member_id', nullable: false)]
    private User $member;

    /** Denormalized from `schedule.coach` at write time — see class docblock. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coach_id', nullable: false)]
    private User $coach;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;

    public function __construct(WorkoutSchedule $schedule, User $member, User $coach, \DateTimeImmutable $startDate)
    {
        $this->id = Uuid::v7();
        $this->schedule = $schedule;
        $this->member = $member;
        $this->coach = $coach;
        $this->status = self::STATUS_ACTIVE;
        $this->startDate = $startDate;
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSchedule(): WorkoutSchedule
    {
        return $this->schedule;
    }

    public function getMember(): User
    {
        return $this->member;
    }

    public function getCoach(): User
    {
        return $this->coach;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    /** setly-phase-workout-scheduling.md §4 step 3: replacement preserves history — logs stay linked, only status transitions. */
    public function markReplaced(): void
    {
        $this->status = self::STATUS_REPLACED;
    }
}

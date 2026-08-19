<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\WorkoutScheduleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * setly-phase-workout-scheduling.md §2.1/§3: a template, authored once by
 * a Coach and *referenced* (never copied) by every WorkoutAssignment that
 * uses it — editing this edits it for every member currently assigned.
 * Gym-wide FK (not per-branch), same shape as Exercise.
 *
 * #[ApiResource(operations: []) — real CRUD lives in
 * WorkoutScheduleController (same "custom write processor confirmed
 * unsafe" reasoning as every other entity in this codebase with a
 * `gym`/`coach` relation into an `operations: []` entity).
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: WorkoutScheduleRepository::class)]
class WorkoutSchedule
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coach_id', nullable: false)]
    private User $coach;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Gym $gym, User $coach, string $name, string $type)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->coach = $coach;
        $this->name = $name;
        $this->type = $type;
        $this->status = self::STATUS_DRAFT;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getCoach(): User
    {
        return $this->coach;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $name, string $type, string $status): void
    {
        $this->name = $name;
        $this->type = $type;
        $this->status = $status;
        $this->touch();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

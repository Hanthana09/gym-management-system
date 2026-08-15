<?php

namespace App\Entity;

use App\Enum\BranchStatus;
use App\Repository\BranchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * roadmap Phase 16 / architecture doc §5.1, §6.12: the physical-location
 * level. `GYM` is now the business; `BRANCH` is where operational data
 * (plans, attendance, PT sessions) actually happens. Every gym gets
 * exactly one `isPrimary` branch — auto-created by this phase's migration
 * for pre-existing gyms, and by GymProvisioningService::ensurePrimaryBranch()
 * for any newly-provisioned gym going forward — so a single-location gym
 * and a multi-branch chain share the exact same data model, no special
 * case for "gyms with only one location."
 */
#[ORM\Entity(repositoryClass: BranchRepository::class)]
class Branch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone;

    /** One branch per gym flagged primary — used as the default in single-branch UIs (DESIGN-SYSTEM.md §4.2). */
    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary;

    #[ORM\Column(length: 20, enumType: BranchStatus::class)]
    private BranchStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Gym $gym, string $name, string $address, ?string $phone = null, bool $isPrimary = false)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->name = $name;
        $this->address = $address;
        $this->phone = $phone;
        $this->isPrimary = $isPrimary;
        $this->status = BranchStatus::ACTIVE;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function getStatus(): BranchStatus
    {
        return $this->status;
    }

    /** functional requirements §14.1: deactivating stops new check-ins/bookings but historical data (past attendance, past sessions) stays intact and reportable — nothing here touches existing rows. */
    public function deactivate(): void
    {
        $this->status = BranchStatus::INACTIVE;
    }

    public function activate(): void
    {
        $this->status = BranchStatus::ACTIVE;
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

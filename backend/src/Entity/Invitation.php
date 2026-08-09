<?php

namespace App\Entity;

use App\Enum\InvitationRole;
use App\Enum\InvitationStatus;
use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's INVITATION entity, with one
 * deliberate deviation: `email` and `phone` are nullable here (the ERD
 * doesn't mark them so, but the invite form takes a single "email or
 * phone" destination per roadmap Phase 3 — only one of the two is ever
 * known for a given invitation).
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by', nullable: false)]
    private User $invitedBy;

    /** Nullable until account exists (architecture doc §5.1). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true)]
    private ?User $user;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone;

    #[ORM\Column(length: 20, enumType: InvitationRole::class)]
    private InvitationRole $role;

    #[ORM\Column(length: 20, enumType: InvitationStatus::class)]
    private InvitationStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        Gym $gym,
        User $invitedBy,
        ?User $user,
        ?string $email,
        ?string $phone,
        InvitationRole $role,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->invitedBy = $invitedBy;
        $this->user = $user;
        $this->email = $email;
        $this->phone = $phone;
        $this->role = $role;
        $this->status = InvitationStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getRole(): InvitationRole
    {
        return $this->role;
    }

    public function getStatus(): InvitationStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::PENDING;
    }

    /** Lazily transitions a stale pending invitation to expired (architecture doc §6.7). */
    public function markExpiredIfNeeded(): bool
    {
        if ($this->isPending() && $this->isExpired()) {
            $this->status = InvitationStatus::EXPIRED;

            return true;
        }

        return false;
    }

    public function approve(): void
    {
        $this->status = InvitationStatus::APPROVED;
        $this->respondedAt = new \DateTimeImmutable();
    }

    public function decline(): void
    {
        $this->status = InvitationStatus::DECLINED;
        $this->respondedAt = new \DateTimeImmutable();
    }
}

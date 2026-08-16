<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\InvitationRole;
use App\Enum\InvitationStatus;
use App\Repository\InvitationRepository;
use App\State\CurrentUserInvitationsProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's INVITATION entity, with one
 * deliberate deviation: `email` and `phone` are nullable here (the ERD
 * doesn't mark them so, but the invite form takes a single "email or
 * phone" destination per roadmap Phase 3 — only one of the two is ever
 * known for a given invitation).
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — InvitationController, under `/api/v1/...`:
 *   - `GET /invitations/me` → resolved via CurrentUserInvitationsProvider
 *     (no `{id}`, always the calling user's own — matched by identity,
 *     same as the real InvitationController::mine(), which applies no
 *     separate Voter check either since the query itself is already
 *     self-scoped).
 * §7's `POST /invitations`, `PATCH /invitations/:id/approve`, and
 * `PATCH /invitations/:id/decline` are NOT declared:
 *   - POST's constructor needs `gym` (operations: [], unaddressable by
 *     IRI), `invitedBy` (must be the calling Owner, never
 *     client-supplied — this Voter's whole point per InvitationVoter::SEND),
 *     and `expiresAt` (architecture doc §9: "defaults to 7 days,"
 *     server-computed, not a client-suppliable value).
 *   - approve()/decline() are no-param domain methods, no setStatus() —
 *     the same "no writable field" pattern as PtSession/Notification/
 *     Invoice's status-changing Patches.
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/invitations/me',
            provider: CurrentUserInvitationsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['groups' => ['invitation:me:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['invitation:me:read'])]
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
    #[Groups(['invitation:me:read'])]
    private ?string $email;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['invitation:me:read'])]
    private ?string $phone;

    #[ORM\Column(length: 20, enumType: InvitationRole::class)]
    #[Groups(['invitation:me:read'])]
    private InvitationRole $role;

    #[ORM\Column(length: 20, enumType: InvitationStatus::class)]
    #[Groups(['invitation:me:read'])]
    private InvitationStatus $status;

    #[ORM\Column]
    #[Groups(['invitation:me:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['invitation:me:read'])]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column]
    #[Groups(['invitation:me:read'])]
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

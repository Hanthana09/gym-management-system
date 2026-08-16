<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\Audience;
use App\Repository\AnnouncementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's ANNOUNCEMENT entity, plus
 * `audience` and `createdBy` — see Audience enum for why `audience` is
 * required by §9.1's AnnouncementVoter. `createdBy` is needed alongside
 * it: when `audience` is OWN_CLIENTS, resolving "which Coach's clients"
 * requires knowing who authored the announcement, not just which gym.
 *
 * `branch` (roadmap Phase 16, nullable) is the Owner's new one-branch-or-
 * gym-wide choice — null means gym-wide (every branch), set means this
 * one branch specifically. A Coach's `own_clients` audience is unchanged
 * by this — that's still a direct client relationship, not branch-mediated.
 *
 * #[ApiResource(operations: []) — §7's only endpoint, `POST
 * /announcements`, is not declared, for two independent reasons:
 *   1. The constructor requires `gym` (Gym, `operations: []` — no route
 *      exists for a client to even reference it via IRI) and `createdBy`
 *      (User) — the latter must be the calling user, never
 *      client-supplied, or a Coach could author an announcement as
 *      someone else. Neither is safely expressible as a bare Post.
 *   2. AnnouncementService's real recipient-resolution logic (who
 *      actually receives it, per audience/branch) lives entirely outside
 *      this entity — a bare Post would create the row but send nothing,
 *      silently not doing what §7 describes at all.
 * No GET is listed for Announcement in §7 either — its content reaches
 * people via Notification, not by reading Announcement directly.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
class Announcement
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: true)]
    private ?Branch $branch;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 20, enumType: Audience::class)]
    private Audience $audience;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Gym $gym, User $createdBy, string $body, Audience $audience, ?Branch $branch = null)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->branch = $branch;
        $this->createdBy = $createdBy;
        $this->body = $body;
        $this->audience = $audience;
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

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAudience(): Audience
    {
        return $this->audience;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

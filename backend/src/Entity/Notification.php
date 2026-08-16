<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Repository\NotificationRepository;
use App\State\CurrentUserNotificationsProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's NOTIFICATION entity, plus a
 * nullable `sourceRole` — needed for the frontend's "color the type tag
 * by source role" rule (this phase's spec, not in the ERD): an
 * announcement or booking notification has an actor whose role should
 * color the tag (Owner/Coach/Member), but a scheduled reminder like
 * membership.expiring has no human actor, hence nullable.
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — the existing hand-written controller, under
 * `/api/v1/...`:
 *   - `GET /notifications` → resolved via
 *     CurrentUserNotificationsProvider (no `{id}`, always the calling
 *     user's own), secured with the coarse `IS_AUTHENTICATED_FULLY`
 *     gate — "any authenticated user, scoped to self" per §7, matching
 *     NotificationVoter::VIEW's own-only rule, enforced by the
 *     provider's query.
 * §7's `PATCH /notifications/:id/read` is NOT declared: `read` only has
 * a one-way `markRead()` domain method, no `setRead()` — there's no
 * writable field for a bare Patch to target, same reasoning as
 * PtSession's status Patch and Invoice's mark-paid Patch.
 * `user` is excluded from the read group — User is `operations: []`.
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/notifications',
            provider: CurrentUserNotificationsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['groups' => ['notification:me:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['notification:me:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    #[ORM\Column(length: 255)]
    #[Groups(['notification:me:read'])]
    private string $title;

    #[ORM\Column(type: 'text')]
    #[Groups(['notification:me:read'])]
    private string $body;

    #[ORM\Column(length: 20, enumType: NotificationType::class)]
    #[Groups(['notification:me:read'])]
    private NotificationType $type;

    #[ORM\Column(length: 20, nullable: true, enumType: UserRole::class)]
    #[Groups(['notification:me:read'])]
    private ?UserRole $sourceRole;

    #[ORM\Column]
    #[Groups(['notification:me:read'])]
    private bool $read;

    #[ORM\Column]
    #[Groups(['notification:me:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $title, string $body, NotificationType $type, ?UserRole $sourceRole = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->title = $title;
        $this->body = $body;
        $this->type = $type;
        $this->sourceRole = $sourceRole;
        $this->read = false;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getSourceRole(): ?UserRole
    {
        return $this->sourceRole;
    }

    public function isRead(): bool
    {
        return $this->read;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markRead(): void
    {
        $this->read = true;
    }
}

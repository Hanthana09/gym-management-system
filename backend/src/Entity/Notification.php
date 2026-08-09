<?php

namespace App\Entity;

use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's NOTIFICATION entity, plus a
 * nullable `sourceRole` — needed for the frontend's "color the type tag
 * by source role" rule (this phase's spec, not in the ERD): an
 * announcement or booking notification has an actor whose role should
 * color the tag (Owner/Coach/Member), but a scheduled reminder like
 * membership.expiring has no human actor, hence nullable.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 20, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 20, nullable: true, enumType: UserRole::class)]
    private ?UserRole $sourceRole;

    #[ORM\Column]
    private bool $read;

    #[ORM\Column]
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

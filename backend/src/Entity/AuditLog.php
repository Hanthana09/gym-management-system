<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Not in architecture doc §5.1's ER diagram — §9's security notes require
 * "full audit log (actor_id, action, entity, timestamp) for any Owner
 * action that touches another user's account... or financial record"
 * (explicitly including invoice mark-paid, §6.9), but no prior phase
 * actually built the table, since this is the first phase with an action
 * that rule clearly requires logging. Fields follow §9's own list, with
 * "entity" split into `entityType`/`entityId` (a bare string can't be
 * queried/joined usefully) and a `metadata` column added to carry
 * context (e.g. payment method) beyond what actor/action/entity alone
 * convey — genuinely new schema, documented here since it isn't in §5.1.
 *
 * #[ApiResource(operations: []) — no §7 endpoint reads or writes audit
 * log entries at all; it's written internally whenever a covered action
 * happens (invoice mark-paid, member suspend, etc.) and has no
 * Owner-facing UI in this codebase yet.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: false)]
    private User $actor;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 100)]
    private string $entityType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $entityId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $metadata */
    public function __construct(User $actor, string $action, string $entityType, Uuid $entityId, array $metadata = [])
    {
        $this->id = Uuid::v7();
        $this->actor = $actor;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): Uuid
    {
        return $this->entityId;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

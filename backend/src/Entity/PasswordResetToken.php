<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\ResetChannel;
use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * gym-management-password-auth.md §2.2. `tokenHash` is a SHA-256 hash of
 * the raw token — the raw value is never persisted, same pattern as
 * OtpCode/RefreshToken. `channel` starts null and is filled in by
 * SendPasswordResetCodeMessageHandler once the async send actually runs
 * (see that handler's docblock) — it's audit metadata only, never
 * consulted by redemption logic.
 *
 * "At most one unused, unexpired token per user" (§2.2) is enforced at
 * the application layer in PasswordResetTokenRepository/PasswordResetService
 * rather than a DB constraint, per this phase's own note that the
 * "unexpired" condition is time-relative and not practical as a Postgres
 * partial index predicate.
 *
 * #[ApiResource(operations: []) — never exposed directly, same reasoning
 * as OtpCode: even read access would leak tokenHash/expiry for other
 * users' in-flight resets.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Index(columns: ['user_id', 'used_at'])]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $tokenHash;

    #[ORM\Column(length: 20, enumType: ResetChannel::class, nullable: true)]
    private ?ResetChannel $channel = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestIp = null;

    public function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt, ?string $requestIp)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->requestIp = $requestIp;
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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getChannel(): ?ResetChannel
    {
        return $this->channel;
    }

    public function setChannel(ResetChannel $channel): void
    {
        $this->channel = $channel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getRequestIp(): ?string
    {
        return $this->requestIp;
    }
}

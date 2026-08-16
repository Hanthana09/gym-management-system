<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\OtpCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's OTP_CODE entity exactly.
 * `codeHash` is never the plaintext code (architecture doc §9).
 *
 * #[ApiResource(operations: []) — §7 has no OTP_CODE-shaped endpoint;
 * `/auth/otp/request` and `/auth/otp/verify` take a destination/code
 * pair and return a JWT token pair, not this entity's own fields, and
 * stay on AuthController. Never exposing this directly is also a hard
 * security requirement on its own: even read access would let a client
 * enumerate codeHash/attempt_count for accounts other than their own
 * before they're even authenticated.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: OtpCodeRepository::class)]
class OtpCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    /** Nullable — may precede account creation (architecture doc §5.1). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    /** The email or phone the code was sent to. */
    #[ORM\Column(length: 255)]
    private string $destination;

    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    public function __construct(?User $user, string $destination, string $codeHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->destination = $destination;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function incrementAttemptCount(): void
    {
        ++$this->attemptCount;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function markConsumed(): void
    {
        $this->consumedAt = new \DateTimeImmutable();
    }

    /** After 5 failed attempts the code is invalidated (functional requirements §1.2). */
    public function isLockedOut(): bool
    {
        return $this->attemptCount >= 5;
    }
}

<?php

namespace App\Entity;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's USER entity, with one deviation:
 * `email` and `phone` are nullable here. Phase 3 needs to create a User
 * from a single OTP destination (email OR phone — architecture doc §6.7's
 * "invitee registers via their first OTP login"), so both being required
 * would make a phone-only or email-only account impossible to represent.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $phone;

    /** Nullable — OTP-only users may never set one (architecture doc §5.1). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 20, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(length: 20, enumType: UserStatus::class)]
    private UserStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** roadmap Phase 15.3 / architecture doc §6.6: opt-in, not opt-out — defaults false so no user starts receiving a channel they never asked for. */
    #[ORM\Column(options: ['default' => false])]
    private bool $whatsappOptIn = false;

    public function __construct(string $name, ?string $email, ?string $phone, UserRole $role, UserStatus $status)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->role = $role;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isWhatsappOptIn(): bool
    {
        return $this->whatsappOptIn;
    }

    public function setWhatsappOptIn(bool $whatsappOptIn): void
    {
        $this->whatsappOptIn = $whatsappOptIn;
    }

    // --- Symfony Security integration ---

    public function getUserIdentifier(): string
    {
        // Password login is always email-based (architecture doc §6.1), but a
        // phone-only user still needs a non-empty identifier for the security
        // system's bookkeeping (they just can't use the password firewall).
        return $this->email ?? $this->phone ?? (string) $this->id;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER', 'ROLE_' . strtoupper($this->role->value)];
    }

    public function eraseCredentials(): void
    {
    }
}

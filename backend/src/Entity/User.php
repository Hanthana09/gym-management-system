<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
 *
 * #[ApiResource(operations: []) — §7's only User-shaped endpoint, `PATCH
 * /users/me/notification-preferences`, needs the exact "custom provider
 * resolving 'me' + write operation" combination confirmed broken on Gym
 * (see Gym's own docblock for the full, empirically-tested finding: a
 * plain `security` check runs before the provider populates `object`,
 * and even past that, the merge-patch deserializer tries to construct a
 * brand-new instance via the constructor rather than merge onto the
 * provided one). User's constructor requires name/email/phone/role/
 * status, so it would hit the identical 400. Not re-tested here since
 * it's the same confirmed pattern, not a new hypothesis.
 * §2's "invite/remove/suspend staff" capability and its own
 * StaffManagementVoter::MANAGE (§9.1) are separately NOT declared here
 * regardless — no `/staff/...`-shaped endpoint appears anywhere in §7's
 * list (it predates roadmap Phase 15's Staff role), so adding one would
 * invent an endpoint beyond what §7 specifies.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
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

    /**
     * gym-management-password-auth.md §2.1: true whenever an Owner
     * assigns/resets this user's password on their behalf (forces the
     * mandatory "set a new password" gate on next login); false once the
     * user has set their own password (self-service change, or the
     * forgot-password flow). Meaningless while passwordHash is null
     * (OTP-only account, no password to be forced to change) — callers
     * must check passwordHash !== null first.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $requiresPasswordChange = true;

    /** Audit: which Owner last set this user's password; null if the user set it themself. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $passwordSetBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordSetAt = null;

    #[ORM\Column(length: 20, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(length: 20, enumType: UserStatus::class)]
    private UserStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** roadmap Phase 15.3 / architecture doc §6.6: opt-in, not opt-out — defaults false so no user starts receiving a channel they never asked for. */
    #[ORM\Column(options: ['default' => false])]
    private bool $whatsappOptIn = false;

    /**
     * roadmap Phase 16 / architecture doc §5.1, §6.12: Coach/Staff ↔
     * Branch. Never populated for a Member or Owner — Members are
     * hub-scoped (architecture doc §5.2), Owners implicitly have every
     * branch. Inverse side kept in sync by BranchAssignment's own
     * constructor, not Doctrine cascade, so AppVoter::hasAssignedBranch()
     * works against freshly-constructed, not-yet-persisted entities in
     * unit tests too (no EntityManager required).
     *
     * @var Collection<int, BranchAssignment>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: BranchAssignment::class)]
    private Collection $branchAssignments;

    public function __construct(string $name, ?string $email, ?string $phone, UserRole $role, UserStatus $status)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->role = $role;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->branchAssignments = new ArrayCollection();
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

    public function isRequiresPasswordChange(): bool
    {
        return $this->requiresPasswordChange;
    }

    public function setRequiresPasswordChange(bool $requiresPasswordChange): void
    {
        $this->requiresPasswordChange = $requiresPasswordChange;
    }

    public function getPasswordSetBy(): ?self
    {
        return $this->passwordSetBy;
    }

    public function setPasswordSetBy(?self $passwordSetBy): void
    {
        $this->passwordSetBy = $passwordSetBy;
    }

    public function getPasswordSetAt(): ?\DateTimeImmutable
    {
        return $this->passwordSetAt;
    }

    public function setPasswordSetAt(?\DateTimeImmutable $passwordSetAt): void
    {
        $this->passwordSetAt = $passwordSetAt;
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

    /** @return Collection<int, BranchAssignment> */
    public function getBranchAssignments(): Collection
    {
        return $this->branchAssignments;
    }

    /** Called from BranchAssignment's own constructor to keep this side in sync — not meant to be called directly elsewhere. */
    public function addBranchAssignment(BranchAssignment $assignment): void
    {
        if (!$this->branchAssignments->contains($assignment)) {
            $this->branchAssignments->add($assignment);
        }
    }

    public function removeBranchAssignment(BranchAssignment $assignment): void
    {
        $this->branchAssignments->removeElement($assignment);
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

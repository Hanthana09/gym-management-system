<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\BranchStatus;
use App\Repository\BranchRepository;
use App\Security\Voter\BranchVoter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * roadmap Phase 16 / architecture doc §5.1, §6.12: the physical-location
 * level. `GYM` is now the business; `BRANCH` is where operational data
 * (plans, attendance, PT sessions) actually happens. Every gym gets
 * exactly one `isPrimary` branch — auto-created by this phase's migration
 * for pre-existing gyms, and by GymProvisioningService::ensurePrimaryBranch()
 * for any newly-provisioned gym going forward — so a single-location gym
 * and a multi-branch chain share the exact same data model, no special
 * case for "gyms with only one location."
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — BranchController, which stays the live route the
 * frontend calls. Declared under `/api/v1/...` so it can never collide:
 *   - `GET /branches` → GetCollection, "any authenticated user" per §7's
 *     own wording (no BranchVoter check on the list itself) — uses the
 *     default Doctrine collection provider; in this single-gym product
 *     (CLAUDE.md) "all Branch rows" already means "the gym's branches,"
 *     same collapse every Voter in this codebase already relies on.
 *   - `POST /branches`, `PATCH /branches/{id}` → secured with
 *     BranchVoter::MANAGE (§9.1) exactly as the real controller does.
 *     Post uses `securityPostDenormalize` (not `security`) since
 *     MANAGE's check reads `object.gym`, which only exists once the
 *     client-supplied `gym` IRI has been denormalized onto the object.
 * §7's `POST/DELETE /branches/:id/assign` are deliberately NOT declared
 * here — they act on BranchAssignment through a `{userId}` request body,
 * not a resource representation a REST client would supply directly, so
 * forcing them into ApiResource's create/delete-a-representation model
 * would misrepresent the action. See BranchAssignment's own docblock.
 * Post/Patch here use the default Doctrine processor — field persistence
 * only, not BranchService's own validation.
 *
 * `branch:write` deliberately excludes `isPrimary`: it's a plain
 * constructor parameter (default `false`) with no setter, so without
 * this restriction a client's POST body could set `isPrimary: true`
 * directly and break the "exactly one primary branch per gym" invariant
 * every migration/provisioning path in this codebase maintains.
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/branches',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['groups' => ['branch:read']],
        ),
        new Post(
            uriTemplate: '/branches',
            securityPostDenormalize: "is_granted('" . BranchVoter::MANAGE . "', object)",
            normalizationContext: ['groups' => ['branch:read']],
            denormalizationContext: ['groups' => ['branch:write']],
        ),
        new Patch(
            uriTemplate: '/branches/{id}',
            security: "is_granted('" . BranchVoter::MANAGE . "', object)",
            normalizationContext: ['groups' => ['branch:read']],
            denormalizationContext: ['groups' => ['branch:write']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: BranchRepository::class)]
class Branch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['branch:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    #[Groups(['branch:read', 'branch:write'])]
    private Gym $gym;

    #[ORM\Column(length: 255)]
    #[Groups(['branch:read', 'branch:write'])]
    private string $name;

    #[ORM\Column(length: 255)]
    #[Groups(['branch:read', 'branch:write'])]
    private string $address;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['branch:read', 'branch:write'])]
    private ?string $phone;

    /** One branch per gym flagged primary — used as the default in single-branch UIs (DESIGN-SYSTEM.md §4.2). Deliberately absent from branch:write — see class docblock. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['branch:read'])]
    private bool $isPrimary;

    #[ORM\Column(length: 20, enumType: BranchStatus::class)]
    #[Groups(['branch:read'])]
    private BranchStatus $status;

    #[ORM\Column]
    #[Groups(['branch:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Gym $gym, string $name, string $address, ?string $phone = null, bool $isPrimary = false)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->name = $name;
        $this->address = $address;
        $this->phone = $phone;
        $this->isPrimary = $isPrimary;
        $this->status = BranchStatus::ACTIVE;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function getStatus(): BranchStatus
    {
        return $this->status;
    }

    /** functional requirements §14.1: deactivating stops new check-ins/bookings but historical data (past attendance, past sessions) stays intact and reportable — nothing here touches existing rows. */
    public function deactivate(): void
    {
        $this->status = BranchStatus::INACTIVE;
    }

    public function activate(): void
    {
        $this->status = BranchStatus::ACTIVE;
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

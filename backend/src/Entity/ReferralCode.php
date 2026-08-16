<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ReferralCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * roadmap Phase 9.2 (GTM Pillar F — Owner-to-owner referral, "refer a
 * gym, both get a free month"). One stable code per Owner, lazily
 * provisioned the same way Gym is (see GymProvisioningService) — a code
 * exists the first time an Owner asks for it, not via a separate setup
 * step. `usageCount` is a plain counter.
 *
 * `creditsAvailable` (added in Phase 10, now that billing exists to
 * apply it to): each redemption grants this Owner one credit, consumed
 * automatically against the next invoice issued for one of their
 * members (BillingService::issueInvoiceForMembership()) — the concrete
 * form "a free month" takes in a schema that only models Member-pays-gym
 * billing, not a separate Owner/platform subscription.
 *
 * #[ApiResource(operations: []) — a roadmap Phase 9/10 addition with no
 * corresponding endpoint in §7 at all (§7 predates the go-to-market
 * phases entirely, same gap as branches/referral-leads/gym-name/etc.).
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ReferralCodeRepository::class)]
class ReferralCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, unique: true)]
    private User $owner;

    #[ORM\Column(length: 32, unique: true)]
    private string $code;

    #[ORM\Column]
    private int $usageCount;

    #[ORM\Column]
    private int $creditsAvailable;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $code)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->code = $code;
        $this->usageCount = 0;
        $this->creditsAvailable = 0;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function incrementUsage(): void
    {
        ++$this->usageCount;
    }

    public function getCreditsAvailable(): int
    {
        return $this->creditsAvailable;
    }

    public function grantCredit(): void
    {
        ++$this->creditsAvailable;
    }

    public function hasCredit(): bool
    {
        return $this->creditsAvailable > 0;
    }

    /** Precondition (hasCredit()) is the caller's responsibility — same split as every other entity in this codebase. */
    public function consumeCredit(): void
    {
        --$this->creditsAvailable;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

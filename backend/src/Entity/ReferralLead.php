<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\ReferralLeadStatus;
use App\Repository\ReferralLeadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * roadmap Phase 9.2 (GTM Pillar B — Coach-led growth, Pillar F — Owner
 * referral). "A lightweight capture-and-notify endpoint" — this entity is
 * the capture; there is deliberately no relationship here to an actual
 * new Gym/tenant. Provisioning a real customer from a converted lead
 * stays a manual sales step, not something this entity or its service
 * automates.
 *
 * #[ApiResource(operations: []) — same as ReferralCode: a roadmap Phase
 * 9 addition with no corresponding endpoint anywhere in §7.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ReferralLeadRepository::class)]
class ReferralLead
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'referred_by', nullable: false)]
    private User $referredBy;

    #[ORM\Column(length: 255)]
    private string $prospectGymName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $contactPhone;

    #[ORM\Column(length: 20, enumType: ReferralLeadStatus::class)]
    private ReferralLeadStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $referredBy,
        string $prospectGymName,
        ?string $contactName,
        ?string $contactEmail,
        ?string $contactPhone,
    ) {
        $this->id = Uuid::v7();
        $this->referredBy = $referredBy;
        $this->prospectGymName = $prospectGymName;
        $this->contactName = $contactName;
        $this->contactEmail = $contactEmail;
        $this->contactPhone = $contactPhone;
        $this->status = ReferralLeadStatus::NEW;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReferredBy(): User
    {
        return $this->referredBy;
    }

    public function getProspectGymName(): string
    {
        return $this->prospectGymName;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function getStatus(): ReferralLeadStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

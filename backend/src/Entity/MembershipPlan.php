<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\MembershipPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's MEMBERSHIP_PLAN entity, updated by
 * roadmap Phase 16: `gym_id` became `branch_id` — plans are now set per
 * branch (architecture doc §5.2: "MEMBERSHIP_PLAN.branch_id (per-branch
 * pricing) means MEMBERSHIP.plan_id indirectly ties a member to an
 * 'enrolling branch' ... informational, not restrictive — it does not
 * gate where they can check in").
 *
 * #[ApiResource(operations: []) — §7's list has no membership-plan
 * endpoint at all (the real `/membership-plans` CRUD, MembershipController,
 * is a roadmap Phase 4 addition that postdates §7's original text — the
 * same "§7 predates most of this app" gap as branches/PT-session
 * branch-scoping/referrals/etc.). Adding operations here would invent an
 * endpoint beyond what §7 specifies.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: MembershipPlanRepository::class)]
class MembershipPlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: false)]
    private Branch $branch;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $price;

    #[ORM\Column]
    private int $durationDays;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $features;

    /**
     * @param string[] $features
     */
    public function __construct(Branch $branch, string $name, string $price, int $durationDays, array $features)
    {
        $this->id = Uuid::v7();
        $this->branch = $branch;
        $this->name = $name;
        $this->price = $price;
        $this->durationDays = $durationDays;
        $this->features = $features;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    /** Convenience delegate so every existing caller written against "the plan's gym" (MembershipVoter, AttendanceService) keeps working unchanged. */
    public function getGym(): Gym
    {
        return $this->branch->getGym();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): void
    {
        $this->durationDays = $durationDays;
    }

    /** @return string[] */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @param string[] $features */
    public function setFeatures(array $features): void
    {
        $this->features = $features;
    }
}

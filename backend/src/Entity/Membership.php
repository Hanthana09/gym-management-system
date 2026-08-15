<?php

namespace App\Entity;

use App\Enum\MembershipStatus;
use App\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's MEMBERSHIP entity, plus a
 * `cancelled` status value — see MembershipStatus for why.
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\ManyToOne(targetEntity: MembershipPlan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false)]
    private MembershipPlan $plan;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(length: 20, enumType: MembershipStatus::class)]
    private MembershipStatus $status;

    #[ORM\Column]
    private bool $autoRenew;

    /**
     * Added in Phase 11 — not in architecture doc §5.1's MEMBERSHIP
     * columns, but needed to compute DAILY_METRIC_SNAPSHOT.cancelled_
     * members_count per day: `status` alone can't say *when* a
     * cancellation happened. Nullable and stays null forever for any
     * membership cancelled before this field existed — the nightly
     * aggregation job's backfill honestly shows 0 cancellations on those
     * past days rather than guessing a date (see DailyMetricAggregator's
     * docblock).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(
        MemberProfile $member,
        MembershipPlan $plan,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        bool $autoRenew = false,
    ) {
        $this->id = Uuid::v7();
        $this->member = $member;
        $this->plan = $plan;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->status = MembershipStatus::ACTIVE;
        $this->autoRenew = $autoRenew;
        $member->addMembership($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMember(): MemberProfile
    {
        return $this->member;
    }

    public function getPlan(): MembershipPlan
    {
        return $this->plan;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    /** A membership still holds the slot even while paused — only active+paused count as "in the way" of a plan deletion or a new enrollment. */
    public function isOngoing(): bool
    {
        return $this->status === MembershipStatus::ACTIVE || $this->status === MembershipStatus::PAUSED;
    }

    public function isNaturallyExpired(): bool
    {
        return $this->status === MembershipStatus::ACTIVE && $this->endDate < new \DateTimeImmutable('today');
    }

    /** Lazily transitions a stale active membership to expired (same pattern as Invitation, Phase 3). */
    public function markExpiredIfNeeded(): bool
    {
        if ($this->isNaturallyExpired()) {
            $this->status = MembershipStatus::EXPIRED;

            return true;
        }

        return false;
    }

    public function pause(): void
    {
        $this->status = MembershipStatus::PAUSED;
    }

    public function resume(): void
    {
        $this->status = MembershipStatus::ACTIVE;
    }

    public function cancel(): void
    {
        $this->status = MembershipStatus::CANCELLED;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /** Days until expiry, used by the scheduled reminder job (architecture doc §8.3) to match the 7/3/1 thresholds. */
    public function daysUntilExpiry(): int
    {
        return (int) (new \DateTimeImmutable('today'))->diff($this->endDate)->format('%r%a');
    }
}

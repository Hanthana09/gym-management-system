<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Enum\MembershipStatus;
use App\Repository\MembershipRepository;
use App\Security\Voter\MembershipVoter;
use App\State\CurrentMembershipProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's MEMBERSHIP entity, plus a
 * `cancelled` status value — see MembershipStatus for why.
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — MemberController's own `/members/me/membership`
 * route, under `/api/v1/...`:
 *   - `GET /members/me/membership` → resolved via
 *     CurrentMembershipProvider (no `{id}` — always the calling
 *     Member's own), secured with MembershipVoter::VIEW. Confirmed safe:
 *     unlike Patch/Post, a custom provider works correctly for Get (see
 *     Gym's docblock for the confirmed Patch-specific failure this
 *     avoids by only doing a Get here).
 * `membership:me:read` deliberately excludes the `plan` and `member`
 * relations: MembershipPlan and MemberProfile are both `operations: []`
 * in this pass (see their own docblocks — no §7 endpoint for the
 * former, a confirmed IRI-generation failure for the latter), so
 * embedding either as a linked resource would itself fail to serialize.
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/members/me/membership',
            uriVariables: [],
            provider: CurrentMembershipProvider::class,
            security: "is_granted('" . MembershipVoter::VIEW . "', object)",
            normalizationContext: ['groups' => ['membership:me:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['membership:me:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\ManyToOne(targetEntity: MembershipPlan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false)]
    private MembershipPlan $plan;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['membership:me:read'])]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['membership:me:read'])]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(length: 20, enumType: MembershipStatus::class)]
    #[Groups(['membership:me:read'])]
    private MembershipStatus $status;

    #[ORM\Column]
    #[Groups(['membership:me:read'])]
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
    #[Groups(['membership:me:read'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * Added by gym-management-billing-v1.md §3.1 (merged into Membership
     * rather than a separate MemberSubscription entity — see that spec's
     * reconciliation note). Both nullable and left unset for every
     * Membership row that existed before this phase — no backfill. Set at
     * enrollment for every new Membership going forward (MembershipService
     * ::enroll()), which is what actually opts a member into the recurring
     * billing engine: InvoiceGenerationService and CheckInEligibilityChecker
     * only ever look at memberships where these are non-null.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['membership:me:read'])]
    private ?int $billingAnchorDay = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['membership:me:read'])]
    private ?\DateTimeImmutable $nextBillingDate = null;

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

    public function getBillingAnchorDay(): ?int
    {
        return $this->billingAnchorDay;
    }

    public function getNextBillingDate(): ?\DateTimeImmutable
    {
        return $this->nextBillingDate;
    }

    /** Called once, at enrollment, by MembershipService::enroll() — opts this Membership into the recurring billing engine. */
    public function enableRecurringBilling(int $billingAnchorDay, \DateTimeImmutable $nextBillingDate): void
    {
        $this->billingAnchorDay = $billingAnchorDay;
        $this->nextBillingDate = $nextBillingDate;
    }

    /** InvoiceGenerationService, after issuing this cycle's invoice. */
    public function advanceNextBillingDate(\DateTimeImmutable $nextBillingDate): void
    {
        $this->nextBillingDate = $nextBillingDate;
    }

    /** gym-management-billing-v1.md §5.2 point 5 — a permanent anchor-day change, not a one-time nudge. */
    public function resetBillingCycle(int $billingAnchorDay, \DateTimeImmutable $nextBillingDate): void
    {
        $this->billingAnchorDay = $billingAnchorDay;
        $this->nextBillingDate = $nextBillingDate;
    }

    /**
     * A membership still holds the slot even while paused or suspended —
     * active+paused+suspended count as "in the way" of a plan deletion or
     * a new enrollment. A suspended member still owes on this membership,
     * so they can't sidestep it by re-enrolling into a fresh one.
     */
    public function isOngoing(): bool
    {
        return in_array($this->status, [MembershipStatus::ACTIVE, MembershipStatus::PAUSED, MembershipStatus::SUSPENDED], true);
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

    /** gym-management-billing-v1.md §5.4 — Owner/Staff enforcement action. Existing PENDING/ABSENT invoices are left untouched by design. */
    public function suspend(): void
    {
        $this->status = MembershipStatus::SUSPENDED;
    }

    /**
     * $nextBillingDate is the reactivation date itself — deliberately not
     * backfilled for the suspended months (§5.4). Null for a membership
     * that was never on the recurring engine to begin with (billingAnchorDay
     * unset) — reactivating a legacy membership doesn't newly opt it in.
     */
    public function reactivate(?\DateTimeImmutable $nextBillingDate): void
    {
        $this->status = MembershipStatus::ACTIVE;
        if ($nextBillingDate !== null) {
            $this->nextBillingDate = $nextBillingDate;
        }
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

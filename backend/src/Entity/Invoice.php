<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentMethod;
use App\Repository\InvoiceRepository;
use App\State\CurrentMemberInvoicesProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's INVOICE entity, including
 * `payment_method`, `recorded_by`, and `paid_at` (roadmap Phase 10's
 * explicit callout, not just `amount`/`status`). One reading beyond the
 * doc's literal column list: `payment_method` isn't marked nullable
 * there, but §6.9 only ever supplies it at mark-paid time — a pending
 * invoice has no payment method yet, same as `recorded_by`/`paid_at`
 * being "null until paid" per the doc's own field notes. All three stay
 * null together until `markPaid()` sets them as one unit.
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — InvoiceController, under `/api/v1/...`:
 *   - `GET /members/me/invoices` → resolved via
 *     CurrentMemberInvoicesProvider (no `{id}`, always the calling
 *     Member's own), secured with the coarse ROLE_MEMBER gate — same
 *     "provider does the real scoping" reasoning as WorkoutLog/BodyMetric,
 *     matching InvoiceVoter::VIEW's "Member: own only" rule.
 * §7's `GET /invoices` (Owner, all) is NOT declared: the default
 * collection provider has no way to apply InvoiceVoter::VIEW's
 * `?branch_id` filter-by-enrolling-plan behavior the real controller
 * supports, and this pass doesn't add query-parameter filtering logic.
 * §7's `PATCH /invoices/:id/mark-paid` is NOT declared either:
 * `markPaid()` sets status/paymentMethod/recordedBy/paidAt together as
 * one unit with no individual setters — there's no writable field for a
 * bare Patch to target, same reasoning as PtSession's status Patch.
 * `membership` is excluded from the read group below: Membership's only
 * operation is itself a singleton "me" Get with no `{id}` route, so it
 * can't be resolved as a linked resource either (same constraint that
 * excludes `plan`/`member` from Membership's own read group).
 * `recordedBy` (User) is excluded too — User is `operations: []`.
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/members/me/invoices',
            provider: CurrentMemberInvoicesProvider::class,
            security: "is_granted('ROLE_MEMBER')",
            normalizationContext: ['groups' => ['invoice:me:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
class Invoice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['invoice:me:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(name: 'membership_id', nullable: false)]
    private Membership $membership;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    #[Groups(['invoice:me:read'])]
    private string $amount;

    #[ORM\Column(length: 20, enumType: InvoiceStatus::class)]
    #[Groups(['invoice:me:read'])]
    private InvoiceStatus $status;

    #[ORM\Column(length: 20, enumType: PaymentMethod::class, nullable: true)]
    #[Groups(['invoice:me:read'])]
    private ?PaymentMethod $paymentMethod = null;

    /** Owner who marked it paid — null until paid, never a Member (architecture doc §5.1, enforced by InvoiceVoter::MARK_PAID). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by', nullable: true)]
    private ?User $recordedBy = null;

    #[ORM\Column]
    #[Groups(['invoice:me:read'])]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:me:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(Membership $membership, string $amount)
    {
        $this->id = Uuid::v7();
        $this->membership = $membership;
        $this->amount = $amount;
        $this->status = InvoiceStatus::PENDING;
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMembership(): Membership
    {
        return $this->membership;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    /**
     * Unconditional — precondition checks (e.g. "must currently be
     * pending") live in BillingService, same split already established
     * between Membership (entity: unconditional pause()/resume()/cancel())
     * and MembershipService (guards the state machine).
     */
    public function markPaid(User $recordedBy, PaymentMethod $method): void
    {
        $this->status = InvoiceStatus::PAID;
        $this->paymentMethod = $method;
        $this->recordedBy = $recordedBy;
        $this->paidAt = new \DateTimeImmutable();
    }
}

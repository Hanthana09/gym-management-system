<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\PaymentMethod;
use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * New entity, gym-management-billing-v1.md §3.3 — the per-payment audit
 * trail for the recurring billing flow (`resetBillingCycle`, `method`,
 * `note` per payment). The existing one-time enrollment `Invoice` keeps
 * its own inline `paymentMethod`/`recordedBy`/`paidAt` fields untouched —
 * BillingService::recordRecurringPayment() sets both: the Invoice's
 * inline fields (via the existing markPaid(), so every existing read path
 * keeps working unchanged) and this Payment row (the new, fuller record).
 *
 * `operations: []` — no API Platform surface, reached only through
 * InvoiceController, same pattern as Expense.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Invoice $invoice;

    /** Must equal invoice.amount exactly — validated in BillingService::recordRecurringPayment(), not here (no partial payments, §3.3/§5.2). */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by', nullable: false)]
    private User $recordedBy;

    #[ORM\Column]
    private \DateTimeImmutable $paidAt;

    /** Audit flag — was the anchor-day reset applied on this payment. Owner-only, enforced by InvoicePaymentVoter::RESET_BILLING_CYCLE. */
    #[ORM\Column]
    private bool $resetBillingCycle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    public function __construct(
        Invoice $invoice,
        string $amount,
        PaymentMethod $method,
        User $recordedBy,
        bool $resetBillingCycle,
        ?string $note = null,
    ) {
        $this->id = Uuid::v7();
        $this->invoice = $invoice;
        $this->amount = $amount;
        $this->method = $method;
        $this->recordedBy = $recordedBy;
        $this->paidAt = new \DateTimeImmutable();
        $this->resetBillingCycle = $resetBillingCycle;
        $this->note = $note;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function getRecordedBy(): User
    {
        return $this->recordedBy;
    }

    public function getPaidAt(): \DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function isResetBillingCycle(): bool
    {
        return $this->resetBillingCycle;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}

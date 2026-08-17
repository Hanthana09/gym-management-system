<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ExpenseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's EXPENSE entity exactly.
 * Branch-level event (§6.13): `branch` is required, gym is derivable
 * through it, same as ATTENDANCE_LOG/PT_SESSION elsewhere in this
 * codebase. `recordedBy` is Owner or Staff — never Coach/Member, enforced
 * by ExpenseVoter (§9.1), not here.
 *
 * #[ApiResource(operations: []) — real CRUD lives in ExpenseController.
 * Two independent blockers, either one sufficient on its own:
 *   1. `category` (ExpenseCategory) and `recordedBy` (User) both point at
 *      `operations: []` entities with no resolvable IRI (see
 *      ExpenseCategory's own docblock; User's is the same confirmed
 *      finding as Gym's).
 *   2. `amount` validation (positive, per functional requirements §15.1)
 *      and Staff's branch-scoped CREATE (ExpenseVoter::CREATE via
 *      hasAssignedBranch()) both need to run against the fully-denormalized
 *      candidate object before persist — the same "securityPostDenormalize
 *      handles auth, but real validation needs a service layer" split
 *      already established by BranchController/MembershipService.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
class Expense
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: false)]
    private Branch $branch;

    #[ORM\ManyToOne(targetEntity: ExpenseCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    private ExpenseCategory $category;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    /** Default LKR, per architecture doc §5.1. */
    #[ORM\Column(length: 8)]
    private string $currency;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $expenseDate;

    /** Owner or Staff — never Coach/Member, see ExpenseVoter (§9.1). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by', nullable: false)]
    private User $recordedBy;

    /** nullable — Flysystem local-disk upload, same pattern as profile photos/gym logo, no OCR (§6.13's explicit exclusion). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $receiptUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Branch $branch,
        ExpenseCategory $category,
        string $amount,
        string $currency,
        ?string $description,
        \DateTimeImmutable $expenseDate,
        User $recordedBy,
    ) {
        $this->id = Uuid::v7();
        $this->branch = $branch;
        $this->category = $category;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->description = $description;
        $this->expenseDate = $expenseDate;
        $this->recordedBy = $recordedBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    public function getCategory(): ExpenseCategory
    {
        return $this->category;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getExpenseDate(): \DateTimeImmutable
    {
        return $this->expenseDate;
    }

    public function getRecordedBy(): User
    {
        return $this->recordedBy;
    }

    public function getReceiptUrl(): ?string
    {
        return $this->receiptUrl;
    }

    public function setReceiptUrl(?string $receiptUrl): void
    {
        $this->receiptUrl = $receiptUrl;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Owner-only mutation (ExpenseVoter::MANAGE) — functional requirements §15.1: "only the Owner can edit or delete an expense." */
    public function update(ExpenseCategory $category, string $amount, ?string $description, \DateTimeImmutable $expenseDate): void
    {
        $this->category = $category;
        $this->amount = $amount;
        $this->description = $description;
        $this->expenseDate = $expenseDate;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

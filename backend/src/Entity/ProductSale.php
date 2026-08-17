<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\RetailPaymentMethod;
use App\Repository\ProductSaleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's PRODUCT_SALE entity exactly. A
 * standalone sales ledger, not inventory or billing (§6.13): no stock
 * counts, no reorder logic, and `member` is nullable and NEVER referenced
 * by/referencing Invoice or Membership — filtering/reporting only, per
 * `member`'s own docblock below and the class-level ER note.
 * `unitPriceAtSale`/`totalAmount` are snapshotted server-side at
 * construction time from `Product::getUnitPrice()` — a later catalog
 * price change never rewrites a past sale's recorded figures (functional
 * requirements §15.3).
 *
 * #[ApiResource(operations: []) — same category of blocker as Expense's
 * docblock: `product` (operations: []), `member` (MemberProfile,
 * operations: [] — confirmed shared-PK IRI failure), and `soldBy` (User,
 * operations: []) are all unresolvable as client-supplied IRIs, and the
 * price-snapshotting logic itself needs to run server-side in a real
 * service, not a bare denormalize-and-persist. Real CRUD lives in
 * ProductSaleController, gated by ProductSaleVoter (§9.1).
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ProductSaleRepository::class)]
class ProductSale
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: false)]
    private Branch $branch;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private Product $product;

    /**
     * nullable — walk-in sales allowed; record-keeping/reporting only,
     * never billing (architecture doc §5.1/§6.13). MUST NOT be used to
     * derive any Invoice/Membership state — filtering/reporting is its
     * only legitimate use, enforced by convention (no such join exists
     * anywhere in Billing/Membership code) since there is nothing to
     * technically prevent a future misuse without this note.
     */
    #[ORM\ManyToOne(targetEntity: MemberProfile::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: true)]
    private ?MemberProfile $member;

    #[ORM\Column]
    private int $quantity;

    /** Snapshotted at sale time — see class docblock. */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $unitPriceAtSale;

    /** = unitPriceAtSale * quantity, computed once at construction. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalAmount;

    #[ORM\Column(length: 20, enumType: RetailPaymentMethod::class)]
    private RetailPaymentMethod $paymentMethod;

    /** Owner or Staff — never Coach/Member, see ProductSaleVoter (§9.1). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sold_by', nullable: false)]
    private User $soldBy;

    #[ORM\Column]
    private \DateTimeImmutable $saleDate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Branch $branch,
        Product $product,
        int $quantity,
        RetailPaymentMethod $paymentMethod,
        User $soldBy,
        ?MemberProfile $member = null,
        ?\DateTimeImmutable $saleDate = null,
    ) {
        $this->id = Uuid::v7();
        $this->branch = $branch;
        $this->product = $product;
        $this->member = $member;
        $this->quantity = $quantity;
        // Snapshot: computed here, once, from the catalog price at this
        // exact moment — functional requirements §15.3's "a later catalog
        // price change never changes this sale's recorded figures."
        // Plain float arithmetic + number_format, not bcmath — the ext
        // isn't enabled in this environment (confirmed: `php -m` inside
        // the app container has no bcmath), so this matches
        // RevenueForecaster's own established convention for derived
        // money figures elsewhere in this codebase.
        $this->unitPriceAtSale = $product->getUnitPrice();
        $this->totalAmount = number_format((float) $this->unitPriceAtSale * $quantity, 2, '.', '');
        $this->paymentMethod = $paymentMethod;
        $this->soldBy = $soldBy;
        $this->saleDate = $saleDate ?? new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getMember(): ?MemberProfile
    {
        return $this->member;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPriceAtSale(): string
    {
        return $this->unitPriceAtSale;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function getPaymentMethod(): RetailPaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getSoldBy(): User
    {
        return $this->soldBy;
    }

    public function getSaleDate(): \DateTimeImmutable
    {
        return $this->saleDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

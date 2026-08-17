<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's PRODUCT entity exactly. Catalog is
 * business-wide, not per-branch (`gym`, not `branch`) — same
 * shared-across-branches pattern as GYM.brand_color. Deliberately has NO
 * `unit_cost`/margin field anywhere (§6.13's explicit exclusion — a
 * future retail-analytics phase, not this one).
 *
 * #[ApiResource(operations: []) — same Gym-IRI blocker as
 * ProductCategory's docblock (`gym` and `category` are both
 * `operations: []` relations with no resolvable IRI). Real CRUD (read
 * Owner+Staff via ProductVoter::VIEW, write Owner-only via
 * ProductVoter::MANAGE) lives in ProductController.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    private ProductCategory $category;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $unitPrice;

    /** functional requirements §15.2: deactivating stops it appearing in the sale quick-entry picker; past sales referencing it remain intact and reportable. */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive;

    public function __construct(Gym $gym, ProductCategory $category, string $name, string $unitPrice, ?string $sku = null)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->category = $category;
        $this->name = $name;
        $this->unitPrice = $unitPrice;
        $this->sku = $sku;
        $this->isActive = true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getCategory(): ProductCategory
    {
        return $this->category;
    }

    public function setCategory(ProductCategory $category): void
    {
        $this->category = $category;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): void
    {
        $this->sku = $sku;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): void
    {
        $this->unitPrice = $unitPrice;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }
}

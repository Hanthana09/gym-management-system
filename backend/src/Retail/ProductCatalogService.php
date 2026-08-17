<?php

namespace App\Retail;

use App\Entity\Gym;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Repository\ProductCategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * roadmap Phase 17 / architecture doc §6.13: the retail product catalog
 * — Owner-managed, Staff-readable (functional requirements §15.2).
 * Deliberately has no unit-cost/margin concept anywhere (§6.13's explicit
 * exclusion).
 */
class ProductCatalogService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $productCategories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createCategory(Gym $gym, string $name): ProductCategory
    {
        $category = new ProductCategory($gym, $name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** @return ProductCategory[] */
    public function listCategories(Gym $gym): array
    {
        return $this->productCategories->findByGym($gym);
    }

    /** Owner only (ProductVoter::MANAGE) — blocks deletion if any Product still references this category, same "block, don't orphan" rule as MembershipService::deletePlan(). */
    public function deleteCategory(ProductCategory $category): void
    {
        if ($this->products->existsForCategory($category)) {
            throw new ProductCategoryHasProductsException();
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    public function createProduct(Gym $gym, ProductCategory $category, string $name, string $unitPrice, ?string $sku): Product
    {
        $product = new Product($gym, $category, $name, $unitPrice, $sku);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    public function updateProduct(
        Product $product,
        ?string $name,
        ?ProductCategory $category,
        ?string $unitPrice,
        ?string $sku,
        ?bool $isActive,
    ): void {
        if ($name !== null) {
            $product->setName($name);
        }
        if ($category !== null) {
            $product->setCategory($category);
        }
        if ($unitPrice !== null) {
            $product->setUnitPrice($unitPrice);
        }
        if ($sku !== null) {
            $product->setSku($sku === '' ? null : $sku);
        }
        if ($isActive !== null) {
            $isActive ? $product->activate() : $product->deactivate();
        }
        $this->em->flush();
    }

    /**
     * functional requirements §15.2: active-only for the sale quick-entry
     * picker; the full list (including deactivated) for catalog
     * management screens.
     *
     * @return Product[]
     */
    public function listProducts(Gym $gym, bool $activeOnly = false): array
    {
        return $this->products->findByGym($gym, $activeOnly);
    }
}

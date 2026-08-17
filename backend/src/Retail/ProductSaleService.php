<?php

namespace App\Retail;

use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Entity\Product;
use App\Entity\ProductSale;
use App\Entity\User;
use App\Enum\RetailPaymentMethod;
use App\Repository\ProductSaleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * roadmap Phase 17 / architecture doc §6.13: retail sale recording — a
 * standalone sales ledger, not inventory or billing. `member` is
 * filtering/reporting only (functional requirements §15.3) — this
 * service never touches Invoice/Membership, and the price/total are
 * snapshotted inside ProductSale's own constructor, never recomputed
 * here or anywhere later.
 */
class ProductSaleService
{
    public function __construct(
        private readonly ProductSaleRepository $sales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function create(
        Branch $branch,
        Product $product,
        int $quantity,
        RetailPaymentMethod $paymentMethod,
        User $soldBy,
        ?MemberProfile $member = null,
        ?\DateTimeImmutable $saleDate = null,
    ): ProductSale {
        $sale = new ProductSale($branch, $product, $quantity, $paymentMethod, $soldBy, $member, $saleDate);
        $this->em->persist($sale);
        $this->em->flush();

        return $sale;
    }

    /**
     * @param Branch[]|null $branches
     *
     * @return ProductSale[]
     */
    public function list(?array $branches, ?Product $product, ?MemberProfile $member, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        return $this->sales->findByFilters($branches, $product, $member, $from, $to);
    }
}

<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ProductCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's PRODUCT_CATEGORY entity exactly:
 * gym-scoped, seeded defaults (Apparel, Supplements, Accessories), Owner
 * can add more — same lazy-provisioning approach as ExpenseCategory (see
 * its docblock; no fixtures mechanism exists in this codebase), via
 * GymProvisioningService::ensureDefaultProductCategories().
 *
 * #[ApiResource(operations: []) — same root blocker as ExpenseCategory's
 * docblock: `gym` is the only relation, and Gym is `operations: []`
 * (confirmed empirically incompatible with custom-provider-plus-write).
 * Unlike ExpenseCategory, `ProductVoter` (§9.1) DOES explicitly cover
 * this entity (`supports()` matches `Product || ProductCategory`) — but
 * the Gym-IRI blocker still applies regardless of which Voter would gate
 * it, so real CRUD (read Owner+Staff, write Owner only) lives in
 * ProductCategoryController.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
class ProductCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\Column(length: 255)]
    private string $name;

    public function __construct(Gym $gym, string $name)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->name = $name;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\ExpenseCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's EXPENSE_CATEGORY entity exactly:
 * gym-scoped (not global), seeded defaults (Utilities, Rent, Equipment,
 * Maintenance, Salaries, Other), Owner can add more.
 *
 * roadmap Phase 17: no fixtures/DataFixtures mechanism exists anywhere in
 * this codebase (checked — grep for Fixture/Seed turns up nothing), so
 * the seeded defaults are lazily provisioned the same way
 * GymProvisioningService::ensurePrimaryBranch() provisions a gym's first
 * Branch: GymProvisioningService::ensureDefaultExpenseCategories(),
 * idempotent, called every time a gym is resolved.
 *
 * #[ApiResource(operations: []) — same root blocker as Gym's own
 * docblock: this entity's only relation is `gym`, and Gym is itself
 * `operations: []` (confirmed empirically incompatible with API
 * Platform's custom-provider-plus-write combination), so no IRI a client
 * could supply for it exists for a declarative Post/Patch to denormalize
 * onto. §9.1 also doesn't write out a dedicated Voter for this entity
 * (only `ExpenseVoter`, whose `supports()` matches `Expense` alone) — the
 * real CRUD (read for Owner/Staff, write for Owner only) lives in
 * ExpenseCategoryController with plain role checks, the same "no single
 * per-object subject to check a Voter against" reasoning
 * InvoiceController::list() already documents for its own plain-role-gate
 * case.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: ExpenseCategoryRepository::class)]
class ExpenseCategory
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

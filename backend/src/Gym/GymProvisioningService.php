<?php

namespace App\Gym;

use App\Entity\Branch;
use App\Entity\ExpenseCategory;
use App\Entity\Gym;
use App\Entity\ProductCategory;
use App\Entity\User;
use App\Repository\BranchRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\GymRepository;
use App\Repository\ProductCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single-gym product (CLAUDE.md) — lazily provisions the Owner's one gym
 * the first time they need it (an invite in Phase 3, a membership plan
 * here) rather than requiring a separate "gym setup" screen not yet in
 * the roadmap. Extracted out of InvitationService once MembershipService
 * needed the exact same behavior.
 *
 * roadmap Phase 16: also provisions that gym's primary Branch in the same
 * step — architecture doc §6.12's "every business starts with one
 * isPrimary branch... this isn't optional scaffolding" applies just as
 * much to a gym provisioned today as to the pre-existing ones this
 * phase's migration backfills.
 *
 * roadmap Phase 17: also provisions the gym's default ExpenseCategory
 * (Utilities, Rent, Equipment, Maintenance, Salaries, Other) and
 * ProductCategory (Apparel, Supplements, Accessories) rows, architecture
 * doc §5.1's named seeded defaults. This codebase has no
 * fixtures/DataFixtures mechanism anywhere (checked), so — per this
 * phase's own explicit guidance to use judgment when none exists —
 * lazy, idempotent provisioning here mirrors ensurePrimaryBranch()'s
 * established pattern exactly, rather than introducing a new mechanism
 * for just these two lists.
 */
class GymProvisioningService
{
    private const DEFAULT_EXPENSE_CATEGORIES = ['Utilities', 'Rent', 'Equipment', 'Maintenance', 'Salaries', 'Other'];
    private const DEFAULT_PRODUCT_CATEGORIES = ['Apparel', 'Supplements', 'Accessories'];

    public function __construct(
        private readonly GymRepository $gyms,
        private readonly BranchRepository $branches,
        private readonly ExpenseCategoryRepository $expenseCategories,
        private readonly ProductCategoryRepository $productCategories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureGymForOwner(User $owner): Gym
    {
        $gym = $this->gyms->findOneByOwner($owner);
        if ($gym === null) {
            $gym = new Gym($owner->getName() . "'s Gym", '', $owner);
            $this->em->persist($gym);
            $this->em->flush();
        }

        $this->ensurePrimaryBranch($gym);
        $this->ensureDefaultExpenseCategories($gym);
        $this->ensureDefaultProductCategories($gym);

        return $gym;
    }

    public function ensurePrimaryBranch(Gym $gym): Branch
    {
        $branch = $this->branches->findPrimaryForGym($gym);
        if ($branch !== null) {
            return $branch;
        }

        $branch = new Branch($gym, $gym->getName(), '', null, isPrimary: true);
        $this->em->persist($branch);
        $this->em->flush();

        return $branch;
    }

    /** @return ExpenseCategory[] */
    public function ensureDefaultExpenseCategories(Gym $gym): array
    {
        if ($this->expenseCategories->countForGym($gym) > 0) {
            return $this->expenseCategories->findByGym($gym);
        }

        $created = [];
        foreach (self::DEFAULT_EXPENSE_CATEGORIES as $name) {
            $category = new ExpenseCategory($gym, $name);
            $this->em->persist($category);
            $created[] = $category;
        }
        $this->em->flush();

        return $created;
    }

    /** @return ProductCategory[] */
    public function ensureDefaultProductCategories(Gym $gym): array
    {
        if ($this->productCategories->countForGym($gym) > 0) {
            return $this->productCategories->findByGym($gym);
        }

        $created = [];
        foreach (self::DEFAULT_PRODUCT_CATEGORIES as $name) {
            $category = new ProductCategory($gym, $name);
            $this->em->persist($category);
            $created[] = $category;
        }
        $this->em->flush();

        return $created;
    }
}

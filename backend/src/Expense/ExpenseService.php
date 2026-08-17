<?php

namespace App\Expense;

use App\Entity\Branch;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Gym;
use App\Entity\User;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\ExpenseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * roadmap Phase 17 / architecture doc §6.13: operating expense recording
 * and the gym's expense-category list. Validation (positive amount,
 * required category/branch/date — functional requirements §15.1) is the
 * controller's job before calling in here, same split ExpenseController
 * mirrors from BranchController/MembershipService elsewhere in this
 * codebase — this service is the persistence layer underneath the
 * already-Voter-checked action, not a second place authorization or
 * input validation happens.
 */
class ExpenseService
{
    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly ExpenseCategoryRepository $expenseCategories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createCategory(Gym $gym, string $name): ExpenseCategory
    {
        $category = new ExpenseCategory($gym, $name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** @return ExpenseCategory[] */
    public function listCategories(Gym $gym): array
    {
        return $this->expenseCategories->findByGym($gym);
    }

    /** Owner only (ExpenseCategoryController's plain role gate) — blocks deletion if any Expense still references this category, same "block, don't orphan" rule as MembershipService::deletePlan(). */
    public function deleteCategory(ExpenseCategory $category): void
    {
        if ($this->expenses->existsForCategory($category)) {
            throw new ExpenseCategoryHasExpensesException();
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    public function create(
        Branch $branch,
        ExpenseCategory $category,
        string $amount,
        string $currency,
        ?string $description,
        \DateTimeImmutable $expenseDate,
        User $recordedBy,
    ): Expense {
        $expense = new Expense($branch, $category, $amount, $currency, $description, $expenseDate, $recordedBy);
        $this->em->persist($expense);
        $this->em->flush();

        return $expense;
    }

    /** ExpenseVoter::MANAGE — Owner only, functional requirements §15.1: "only the Owner can edit or delete an expense." */
    public function update(Expense $expense, ExpenseCategory $category, string $amount, ?string $description, \DateTimeImmutable $expenseDate): void
    {
        $expense->update($category, $amount, $description, $expenseDate);
        $this->em->flush();
    }

    public function delete(Expense $expense): void
    {
        $this->em->remove($expense);
        $this->em->flush();
    }

    public function attachReceipt(Expense $expense, string $url): void
    {
        $expense->setReceiptUrl($url);
        $this->em->flush();
    }

    /**
     * @param Branch[]|null $branches
     *
     * @return Expense[]
     */
    public function list(?array $branches, ?ExpenseCategory $category, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        return $this->expenses->findByFilters($branches, $category, $from, $to);
    }
}

<?php

namespace App\Controller;

use App\Entity\ExpenseCategory;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Expense\ExpenseCategoryHasExpensesException;
use App\Expense\ExpenseService;
use App\Gym\GymProvisioningService;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\GymRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7's `/expense-categories` endpoint. No dedicated
 * Voter exists for this entity in §9.1 (only `ExpenseVoter`, whose
 * `supports()` matches `Expense` alone) — plain role checks here, same
 * "no single per-object subject to check a Voter against" reasoning
 * InvoiceController::list() already documents for its own plain-role-gate
 * case. Seeded defaults (Utilities, Rent, Equipment, Maintenance,
 * Salaries, Other) are provisioned lazily via GymProvisioningService —
 * see ExpenseCategory's own docblock.
 */
#[Route('/api')]
class ExpenseCategoryController extends AbstractController
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ExpenseCategoryRepository $expenseCategories,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
    ) {
    }

    /** Owner — manage; Staff — read only (§7). Coach/Member get no route at all in this controller. */
    #[Route('/expense-categories', name: 'expense_categories_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if (!in_array($user->getRole(), [UserRole::OWNER, UserRole::STAFF], true)) {
            return $this->forbidden();
        }

        $gym = $this->resolveGym($user);
        if ($gym === null) {
            return new JsonResponse(['categories' => []]);
        }

        return new JsonResponse(['categories' => array_map(fn (ExpenseCategory $c) => $this->serialize($c), $this->expenses->listCategories($gym))]);
    }

    #[Route('/expense-categories', name: 'expense_categories_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'name is required.'], 400);
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $category = $this->expenses->createCategory($gym, $name);

        return new JsonResponse($this->serialize($category), 201);
    }

    /** Owner only — same plain role gate as create(), no dedicated Voter for this entity (see class docblock). */
    #[Route('/expense-categories/{id}', name: 'expense_categories_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $category = $this->expenseCategories->find($id);
        if ($category === null || $category->getGym() !== $this->gymProvisioning->ensureGymForOwner($user)) {
            return $this->notFound('Expense category not found.');
        }

        try {
            $this->expenses->deleteCategory($category);
        } catch (ExpenseCategoryHasExpensesException $exception) {
            return new JsonResponse(['error' => 'category_has_expenses', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(null, 204);
    }

    private function resolveGym(User $user): ?Gym
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $this->gymProvisioning->ensureGymForOwner($user);
        }

        // Single-gym product (CLAUDE.md) — same read-only fallback BranchController::resolveGymOwnerContext() uses.
        $gym = $this->gyms->findTheOnlyGym();

        return $gym !== null ? $this->gymProvisioning->ensureGymForOwner($gym->getOwner()) : null;
    }

    private function serialize(ExpenseCategory $category): array
    {
        return [
            'id' => (string) $category->getId(),
            'name' => $category->getName(),
        ];
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found', 'message' => $message], 404);
    }

    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }
}

<?php

namespace App\Controller;

use App\Entity\Branch;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Expense\ExpenseService;
use App\Gym\GymProvisioningService;
use App\Repository\BranchRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\GymRepository;
use App\Security\Voter\ExpenseVoter;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7's `/expenses` endpoints + §9.1's `ExpenseVoter`
 * (Phase 17). `create()` accepts multipart/form-data (not JSON) so the
 * optional receipt upload can travel alongside the other fields in one
 * request, same shape as GymBrandingController's logo upload. `update()`
 * stays JSON-only — functional requirements §15.1 doesn't ask for
 * receipt replacement on edit, and only the Owner can reach it anyway
 * (ExpenseVoter::MANAGE).
 */
#[Route('/api')]
class ExpenseController extends AbstractController
{
    private const ALLOWED_RECEIPT_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
    private const MAX_RECEIPT_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ExpenseRepository $expenseRepository,
        private readonly ExpenseCategoryRepository $expenseCategories,
        private readonly BranchRepository $branches,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
        #[Autowire(service: 'expense_receipts.storage')]
        private readonly FilesystemOperator $receipts,
    ) {
    }

    #[Route('/expenses', name: 'expenses_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
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
            return new JsonResponse(['expenses' => []]);
        }

        [$branches, $error] = $this->resolveBranchFilter($user, $gym, $request);
        if ($error !== null) {
            return $error;
        }

        $category = null;
        $categoryId = $request->query->get('category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $category = $this->expenseCategories->find($categoryId);
            if ($category === null || $category->getGym() !== $gym) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'category_id does not belong to this gym.'], 400);
            }
        }

        [$from, $to, $dateError] = $this->parseOptionalDateRange($request);
        if ($dateError !== null) {
            return $dateError;
        }

        $expenses = $this->expenses->list($branches, $category, $from, $to);

        return new JsonResponse(['expenses' => array_map(fn (Expense $e) => $this->serialize($e), $expenses)]);
    }

    #[Route('/expenses', name: 'expenses_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $branchId = (string) $request->request->get('branchId', '');
        $categoryId = (string) $request->request->get('categoryId', '');
        $amountRaw = (string) $request->request->get('amount', '');
        $currency = trim((string) $request->request->get('currency', 'LKR')) ?: 'LKR';
        $description = $request->request->get('description');
        $description = $description !== null && $description !== '' ? (string) $description : null;
        $expenseDateRaw = (string) $request->request->get('expenseDate', '');

        if ($branchId === '' || $categoryId === '' || $expenseDateRaw === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId, categoryId, and expenseDate are required.'], 400);
        }

        if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'amount must be a positive number.'], 400);
        }

        $branch = $this->branches->find($branchId);
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not refer to a real branch.'], 400);
        }
        $category = $this->expenseCategories->find($categoryId);
        if ($category === null || $category->getGym() !== $branch->getGym()) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'categoryId does not refer to a real category for this gym.'], 400);
        }

        try {
            $expenseDate = new \DateTimeImmutable($expenseDateRaw);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'expenseDate must be a valid date (YYYY-MM-DD).'], 400);
        }

        // ExpenseVoter::CREATE checked against a not-yet-persisted candidate — same pattern as BranchController::create().
        $candidate = new Expense($branch, $category, number_format((float) $amountRaw, 2, '.', ''), $currency, $description, $expenseDate, $user);
        if (!$this->isGranted(ExpenseVoter::CREATE, $candidate)) {
            return $this->forbidden();
        }

        $expense = $this->expenses->create($branch, $category, number_format((float) $amountRaw, 2, '.', ''), $currency, $description, $expenseDate, $user);

        $receipt = $request->files->get('receipt');
        if ($receipt instanceof UploadedFile) {
            $error = $this->validateReceipt($receipt);
            if ($error !== null) {
                return $error;
            }
            $extension = match ($receipt->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf',
                default => 'jpg',
            };
            $filename = $expense->getId() . '.' . $extension;
            $this->receipts->write($filename, file_get_contents($receipt->getPathname()));
            $this->expenses->attachReceipt($expense, '/uploads/expense-receipts/' . $filename);
        }

        return new JsonResponse($this->serialize($expense), 201);
    }

    #[Route('/expenses/{id}', name: 'expenses_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $expense = $this->expenseRepository->find($id);
        if ($expense === null) {
            return $this->notFound('Expense not found.');
        }
        if (!$this->isGranted(ExpenseVoter::MANAGE, $expense)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);

        $category = $expense->getCategory();
        if (array_key_exists('categoryId', $data)) {
            $candidate = $this->expenseCategories->find((string) $data['categoryId']);
            if ($candidate === null || $candidate->getGym() !== $expense->getBranch()->getGym()) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'categoryId does not refer to a real category for this gym.'], 400);
            }
            $category = $candidate;
        }

        $amount = $expense->getAmount();
        if (array_key_exists('amount', $data)) {
            $amountRaw = (string) $data['amount'];
            if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'amount must be a positive number.'], 400);
            }
            $amount = number_format((float) $amountRaw, 2, '.', '');
        }

        $description = array_key_exists('description', $data) ? (string) $data['description'] : $expense->getDescription();

        $expenseDate = $expense->getExpenseDate();
        if (array_key_exists('expenseDate', $data)) {
            try {
                $expenseDate = new \DateTimeImmutable((string) $data['expenseDate']);
            } catch (\Exception) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'expenseDate must be a valid date (YYYY-MM-DD).'], 400);
            }
        }

        $this->expenses->update($expense, $category, $amount, $description === '' ? null : $description, $expenseDate);

        return new JsonResponse($this->serialize($expense));
    }

    #[Route('/expenses/{id}', name: 'expenses_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $expense = $this->expenseRepository->find($id);
        if ($expense === null) {
            return $this->notFound('Expense not found.');
        }
        if (!$this->isGranted(ExpenseVoter::MANAGE, $expense)) {
            return $this->forbidden();
        }

        $this->expenses->delete($expense);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array{0: ?array<int, Branch>, 1: ?JsonResponse} null branches = no filter (Owner rollup); [] = Staff with no assignments (a legitimate, empty result set)
     */
    private function resolveBranchFilter(User $user, Gym $gym, Request $request): array
    {
        $branchId = $request->query->get('branch_id');

        if ($user->getRole() === UserRole::OWNER) {
            if ($branchId === null || $branchId === '') {
                return [null, null];
            }
            $branch = $this->branches->find($branchId);
            if ($branch === null || $branch->getGym() !== $gym) {
                return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'branch_id does not belong to this gym.'], 400)];
            }

            return [[$branch], null];
        }

        // Staff: own assigned branch(es) only (ExpenseVoter::VIEW / hasAssignedBranch()).
        $assigned = array_map(fn ($a) => $a->getBranch(), $user->getBranchAssignments()->toArray());
        if ($branchId === null || $branchId === '') {
            return [$assigned, null];
        }
        $branch = $this->branches->find($branchId);
        $isAssigned = $branch !== null && in_array($branch, $assigned, true);
        if (!$isAssigned) {
            return [null, $this->forbidden()];
        }

        return [[$branch], null];
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?JsonResponse} */
    private function parseOptionalDateRange(Request $request): array
    {
        try {
            $from = $request->query->get('from') !== null ? new \DateTimeImmutable((string) $request->query->get('from')) : null;
            $to = $request->query->get('to') !== null ? new \DateTimeImmutable((string) $request->query->get('to')) : null;
        } catch (\Exception) {
            return [null, null, new JsonResponse(['error' => 'invalid_request', 'message' => 'from/to must be valid dates (YYYY-MM-DD).'], 400)];
        }

        return [$from, $to, null];
    }

    private function validateReceipt(UploadedFile $receipt): ?JsonResponse
    {
        if (!$receipt->isValid()) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Receipt upload failed.'], 400);
        }
        if (!in_array($receipt->getMimeType(), self::ALLOWED_RECEIPT_MIME_TYPES, true)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Receipt must be a PNG, JPEG, WebP, or PDF file.'], 400);
        }
        if ($receipt->getSize() === null || $receipt->getSize() > self::MAX_RECEIPT_BYTES) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Receipt must be under 5MB.'], 400);
        }

        return null;
    }

    private function resolveGym(User $user): ?Gym
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $this->gymProvisioning->ensureGymForOwner($user);
        }

        $gym = $this->gyms->findTheOnlyGym();

        return $gym !== null ? $this->gymProvisioning->ensureGymForOwner($gym->getOwner()) : null;
    }

    private function serialize(Expense $expense): array
    {
        return [
            'id' => (string) $expense->getId(),
            'branchId' => (string) $expense->getBranch()->getId(),
            'branchName' => $expense->getBranch()->getName(),
            'category' => [
                'id' => (string) $expense->getCategory()->getId(),
                'name' => $expense->getCategory()->getName(),
            ],
            'amount' => $expense->getAmount(),
            'currency' => $expense->getCurrency(),
            'description' => $expense->getDescription(),
            'expenseDate' => $expense->getExpenseDate()->format('Y-m-d'),
            'recordedByName' => $expense->getRecordedBy()->getName(),
            'receiptUrl' => $expense->getReceiptUrl(),
            'createdAt' => $expense->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $expense->getUpdatedAt()->format(\DateTimeInterface::ATOM),
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

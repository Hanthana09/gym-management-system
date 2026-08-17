<?php

namespace App\Controller;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\Product;
use App\Entity\ProductSale;
use App\Entity\User;
use App\Enum\RetailPaymentMethod;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\BranchRepository;
use App\Repository\GymRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Retail\ProductSaleService;
use App\Security\Voter\ProductSaleVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7's `/product-sales` endpoints + §9.1's
 * `ProductSaleVoter` (Phase 17). `memberId` is optional (walk-in sales,
 * functional requirements §15.3) and is resolved against EXISTING
 * members only via `MemberProfileRepository` — this endpoint never
 * creates a member record (member creation only ever happens through the
 * invite/approve flow, §2/CLAUDE.md).
 */
#[Route('/api')]
class ProductSaleController extends AbstractController
{
    public function __construct(
        private readonly ProductSaleService $sales,
        private readonly ProductRepository $products,
        private readonly BranchRepository $branches,
        private readonly UserRepository $users,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
    ) {
    }

    #[Route('/product-sales', name: 'product_sales_list', methods: ['GET'])]
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
            return new JsonResponse(['sales' => []]);
        }

        [$branches, $error] = $this->resolveBranchFilter($user, $gym, $request);
        if ($error !== null) {
            return $error;
        }

        $product = null;
        $productId = $request->query->get('product_id');
        if ($productId !== null && $productId !== '') {
            $product = $this->products->find($productId);
            if ($product === null || $product->getGym() !== $gym) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'product_id does not belong to this gym.'], 400);
            }
        }

        $member = null;
        $memberId = $request->query->get('member_id');
        if ($memberId !== null && $memberId !== '') {
            $memberUser = $this->users->find($memberId);
            $member = $memberUser !== null ? $this->memberProfiles->findOneByUser($memberUser) : null;
            if ($member === null) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'member_id does not refer to a real member.'], 400);
            }
        }

        [$from, $to, $dateError] = $this->parseOptionalDateRange($request);
        if ($dateError !== null) {
            return $dateError;
        }

        $sales = $this->sales->list($branches, $product, $member, $from, $to);

        return new JsonResponse(['sales' => array_map(fn (ProductSale $s) => $this->serialize($s), $sales)]);
    }

    #[Route('/product-sales', name: 'product_sales_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $branchId = (string) ($data['branchId'] ?? '');
        $productId = (string) ($data['productId'] ?? '');
        $quantity = (int) ($data['quantity'] ?? 0);
        $paymentMethod = RetailPaymentMethod::tryFrom((string) ($data['paymentMethod'] ?? ''));

        if ($branchId === '' || $productId === '' || $paymentMethod === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId, productId, and a valid paymentMethod (cash/card/other) are required.'], 400);
        }
        if ($quantity <= 0) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'quantity must be a positive integer.'], 400);
        }

        $branch = $this->branches->find($branchId);
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not refer to a real branch.'], 400);
        }
        $product = $this->products->find($productId);
        if ($product === null || $product->getGym() !== $branch->getGym()) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'productId does not refer to a real product for this gym.'], 400);
        }

        // functional requirements §15.3: search existing members only — never creates one from this form.
        $member = null;
        if (!empty($data['memberId'])) {
            $memberUser = $this->users->find((string) $data['memberId']);
            $member = $memberUser !== null ? $this->memberProfiles->findOneByUser($memberUser) : null;
            if ($member === null) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'memberId does not refer to a real member.'], 400);
            }
        }

        $saleDate = null;
        if (!empty($data['saleDate'])) {
            try {
                $saleDate = new \DateTimeImmutable((string) $data['saleDate']);
            } catch (\Exception) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'saleDate must be a valid date/time.'], 400);
            }
        }

        // ProductSaleVoter::CREATE checked against a not-yet-persisted candidate — same pattern as ExpenseController::create().
        $candidate = new ProductSale($branch, $product, $quantity, $paymentMethod, $user, $member, $saleDate);
        if (!$this->isGranted(ProductSaleVoter::CREATE, $candidate)) {
            return $this->forbidden();
        }

        $sale = $this->sales->create($branch, $product, $quantity, $paymentMethod, $user, $member, $saleDate);

        return new JsonResponse($this->serialize($sale), 201);
    }

    /** @return array{0: ?array<int, Branch>, 1: ?JsonResponse} */
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
            return [null, null, new JsonResponse(['error' => 'invalid_request', 'message' => 'from/to must be valid dates.'], 400)];
        }

        return [$from, $to, null];
    }

    private function resolveGym(User $user): ?Gym
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $this->gymProvisioning->ensureGymForOwner($user);
        }

        $gym = $this->gyms->findTheOnlyGym();

        return $gym !== null ? $this->gymProvisioning->ensureGymForOwner($gym->getOwner()) : null;
    }

    private function serialize(ProductSale $sale): array
    {
        $member = $sale->getMember();

        return [
            'id' => (string) $sale->getId(),
            'branchId' => (string) $sale->getBranch()->getId(),
            'product' => [
                'id' => (string) $sale->getProduct()->getId(),
                'name' => $sale->getProduct()->getName(),
            ],
            'member' => $member !== null ? ['id' => (string) $member->getUser()->getId(), 'name' => $member->getUser()->getName()] : null,
            'quantity' => $sale->getQuantity(),
            'unitPriceAtSale' => $sale->getUnitPriceAtSale(),
            'totalAmount' => $sale->getTotalAmount(),
            'paymentMethod' => $sale->getPaymentMethod()->value,
            'soldByName' => $sale->getSoldBy()->getName(),
            'saleDate' => $sale->getSaleDate()->format(\DateTimeInterface::ATOM),
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

    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }
}

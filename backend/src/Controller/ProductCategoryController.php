<?php

namespace App\Controller;

use App\Entity\Gym;
use App\Entity\ProductCategory;
use App\Entity\User;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\GymRepository;
use App\Repository\ProductCategoryRepository;
use App\Retail\ProductCatalogService;
use App\Retail\ProductCategoryHasProductsException;
use App\Security\Voter\ProductVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7's `/product-categories` endpoints, gated by
 * `ProductVoter` (§9.1, covers both `Product` and `ProductCategory`).
 * Note: §7's literal text says "any authenticated gym user — read, for
 * catalog pickers," but `ProductVoter::VIEW`'s actual body (and
 * functional requirements §15.2's "Coach or Member... any route...
 * permission error") only grants Owner/Staff — the Voter body and the
 * functional requirements win over §7's representative-endpoint prose
 * per this codebase's own established precedence (CLAUDE.md: "code's
 * conventions over the doc's literal wording where they conflict").
 */
#[Route('/api')]
class ProductCategoryController extends AbstractController
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly ProductCategoryRepository $productCategories,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
    ) {
    }

    #[Route('/product-categories', name: 'product_categories_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->resolveGym($user);
        if ($gym === null) {
            return new JsonResponse(['categories' => []]);
        }

        // Voter-check against a representative (not-yet-persisted) category — no per-item list check needed, every category in this single-gym product belongs to the same gym.
        if (!$this->isGranted(ProductVoter::VIEW, new ProductCategory($gym, ''))) {
            return $this->forbidden();
        }

        return new JsonResponse(['categories' => array_map(fn (ProductCategory $c) => $this->serialize($c), $this->catalog->listCategories($gym))]);
    }

    #[Route('/product-categories', name: 'product_categories_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        // Checked before any gym resolution below — ProductVoter::MANAGE denies
        // everyone but Owner anyway, but ensureGymForOwner() must never run for
        // a non-Owner caller (it would lazily provision a bogus new Gym "owned"
        // by them, since findOneByOwner() naturally finds none for a Staff/Coach/
        // Member user) — same reasoning as ExpenseCategoryController::create().
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'name is required.'], 400);
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ProductVoter::MANAGE, new ProductCategory($gym, $name))) {
            return $this->forbidden();
        }

        $category = $this->catalog->createCategory($gym, $name);

        return new JsonResponse($this->serialize($category), 201);
    }

    /** ProductVoter::MANAGE — Owner only, checked against the actual found category (create() checks a representative not-yet-persisted one instead, same pattern ExpenseController::update() vs ::create() uses). */
    #[Route('/product-categories/{id}', name: 'product_categories_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $category = $this->productCategories->find($id);
        if ($category === null) {
            return $this->notFound('Product category not found.');
        }
        if (!$this->isGranted(ProductVoter::MANAGE, $category)) {
            return $this->forbidden();
        }

        try {
            $this->catalog->deleteCategory($category);
        } catch (ProductCategoryHasProductsException $exception) {
            return new JsonResponse(['error' => 'category_has_products', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(null, 204);
    }

    private function resolveGym(User $user): ?Gym
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $this->gymProvisioning->ensureGymForOwner($user);
        }

        $gym = $this->gyms->findTheOnlyGym();

        return $gym !== null ? $this->gymProvisioning->ensureGymForOwner($gym->getOwner()) : null;
    }

    private function serialize(ProductCategory $category): array
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

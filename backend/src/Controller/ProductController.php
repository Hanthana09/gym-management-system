<?php

namespace App\Controller;

use App\Entity\Gym;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\GymRepository;
use App\Repository\ProductCategoryRepository;
use App\Repository\ProductRepository;
use App\Retail\ProductCatalogService;
use App\Security\Voter\ProductVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7's `/products` endpoints + §9.1's `ProductVoter`
 * (Phase 17). Owner-only for create/update/deactivate; Staff read-only,
 * to make a sale. No `unit_cost`/margin field anywhere, per §6.13's
 * explicit exclusion.
 */
#[Route('/api')]
class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $productCategories,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
    ) {
    }

    #[Route('/products', name: 'products_list', methods: ['GET'])]
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
            return new JsonResponse(['products' => []]);
        }

        $activeOnly = $request->query->getBoolean('active_only');

        return new JsonResponse(['products' => array_map(fn (Product $p) => $this->serialize($p), $this->catalog->listProducts($gym, $activeOnly))]);
    }

    #[Route('/products', name: 'products_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        // Same reasoning as ProductCategoryController::create(): must reject
        // before any gym resolution, or a non-Owner caller triggers a bogus
        // lazily-provisioned Gym "owned" by them.
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $categoryId = (string) ($data['categoryId'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        $unitPriceRaw = (string) ($data['unitPrice'] ?? '');
        $sku = isset($data['sku']) && $data['sku'] !== '' ? trim((string) $data['sku']) : null;

        if ($categoryId === '' || $name === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'categoryId and name are required.'], 400);
        }
        if (!is_numeric($unitPriceRaw) || (float) $unitPriceRaw <= 0) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'unitPrice must be a positive number.'], 400);
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $category = $this->productCategories->find($categoryId);
        if ($category === null || $category->getGym() !== $gym) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'categoryId does not refer to a real category for this gym.'], 400);
        }

        $candidate = new Product($gym, $category, $name, number_format((float) $unitPriceRaw, 2, '.', ''), $sku);
        if (!$this->isGranted(ProductVoter::MANAGE, $candidate)) {
            return $this->forbidden();
        }

        $product = $this->catalog->createProduct($gym, $category, $name, number_format((float) $unitPriceRaw, 2, '.', ''), $sku);

        return new JsonResponse($this->serialize($product), 201);
    }

    #[Route('/products/{id}', name: 'products_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $product = $this->products->find($id);
        if ($product === null) {
            return $this->notFound('Product not found.');
        }
        if (!$this->isGranted(ProductVoter::MANAGE, $product)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);

        $category = null;
        if (array_key_exists('categoryId', $data)) {
            $category = $this->productCategories->find((string) $data['categoryId']);
            if ($category === null || $category->getGym() !== $product->getGym()) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'categoryId does not refer to a real category for this gym.'], 400);
            }
        }

        $unitPrice = null;
        if (array_key_exists('unitPrice', $data)) {
            $unitPriceRaw = (string) $data['unitPrice'];
            if (!is_numeric($unitPriceRaw) || (float) $unitPriceRaw <= 0) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'unitPrice must be a positive number.'], 400);
            }
            $unitPrice = number_format((float) $unitPriceRaw, 2, '.', '');
        }

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : null;
        $sku = array_key_exists('sku', $data) ? (string) $data['sku'] : null;
        $isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : null;

        $this->catalog->updateProduct($product, $name === '' ? null : $name, $category, $unitPrice, $sku, $isActive);

        return new JsonResponse($this->serialize($product));
    }

    private function resolveGym(User $user): ?Gym
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $this->gymProvisioning->ensureGymForOwner($user);
        }

        $gym = $this->gyms->findTheOnlyGym();

        return $gym !== null ? $this->gymProvisioning->ensureGymForOwner($gym->getOwner()) : null;
    }

    private function serialize(Product $product): array
    {
        return [
            'id' => (string) $product->getId(),
            'category' => [
                'id' => (string) $product->getCategory()->getId(),
                'name' => $product->getCategory()->getName(),
            ],
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'unitPrice' => $product->getUnitPrice(),
            'isActive' => $product->isActive(),
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

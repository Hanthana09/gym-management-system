<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\ProviderInterface;
use App\Repository\ExerciseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * setly-phase-exercise-media.md §5/§7: GET /exercises?muscle=&equipment=&category=.
 * Only the matching *id list* is cached (a plain array of strings) —
 * never hydrated Doctrine entities/proxies — so the Redis payload stays
 * trivially serializable and the cached artifact is exactly the
 * expensive part (the JSONB_EXISTS filter computation), not the cheap,
 * always-fresh, PK-indexed hydrate step below it.
 */
final class ExerciseCollectionProvider implements ProviderInterface
{
    private const DEFAULT_ITEMS_PER_PAGE = 30;

    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly CacheInterface $exerciseCatalogCache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $request = $context['request'] ?? null;
        $muscle = $request instanceof Request ? $request->query->get('muscle') : null;
        $equipment = $request instanceof Request ? $request->query->get('equipment') : null;
        $category = $request instanceof Request ? $request->query->get('category') : null;
        $page = max(1, $request instanceof Request ? (int) $request->query->get('page', 1) : 1);
        $itemsPerPage = $request instanceof Request ? (int) $request->query->get('itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE) : self::DEFAULT_ITEMS_PER_PAGE;
        $itemsPerPage = $itemsPerPage > 0 ? $itemsPerPage : self::DEFAULT_ITEMS_PER_PAGE;

        $cacheKey = 'exercise_list_ids.' . md5(json_encode(['muscle' => $muscle, 'equipment' => $equipment, 'category' => $category], JSON_THROW_ON_ERROR));
        $ids = $this->exerciseCatalogCache->get($cacheKey, function (ItemInterface $item) use ($muscle, $equipment, $category) {
            $item->expiresAfter(null); // near-static reference data — invalidated only by ImportExercisesCommand's explicit clear(), not TTL
            return $this->exercises->findFilteredIds($muscle, $equipment, $category);
        });

        $exercises = $ids === [] ? [] : $this->exercises->findBy(['id' => $ids], ['name' => 'ASC']);

        return new ArrayPaginator($exercises, ($page - 1) * $itemsPerPage, $itemsPerPage);
    }
}

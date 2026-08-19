<?php

namespace App\Repository;

use App\Entity\Exercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercise>
 */
class ExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /** ImportExercisesCommand's upsert key. */
    public function findOneBySourceId(string $sourceId): ?Exercise
    {
        return $this->findOneBy(['sourceId' => $sourceId]);
    }

    /**
     * setly-phase-exercise-media.md §5: GET /exercises?muscle=&equipment=&category=.
     * Returns matching ids only (not hydrated entities) — the caller
     * (ExerciseCollectionProvider) caches this id list in Redis and
     * rehydrates by PK, so the cached artifact is a plain array of
     * strings, never Doctrine entities/proxies.
     *
     * `muscle` checks both primary and secondary muscles per the
     * companion prompt's explicit instruction. JSONB_EXISTS is a custom
     * DQL function (config/packages/doctrine.yaml, src/Doctrine/
     * JsonbExistsFunction.php) wrapping Postgres's jsonb_exists() — no
     * native DQL array-contains operator exists.
     *
     * @return string[]
     */
    public function findFilteredIds(?string $muscle, ?string $equipment, ?string $category): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e.id')
            ->orderBy('e.name', 'ASC');

        if ($muscle !== null && $muscle !== '') {
            $qb->andWhere('JSONB_EXISTS(e.primaryMuscles, :muscle) = true OR JSONB_EXISTS(e.secondaryMuscles, :muscle) = true')
                ->setParameter('muscle', $muscle);
        }
        if ($equipment !== null && $equipment !== '') {
            $qb->andWhere('e.equipment = :equipment')->setParameter('equipment', $equipment);
        }
        if ($category !== null && $category !== '') {
            $qb->andWhere('e.category = :category')->setParameter('category', $category);
        }

        return array_map(fn (mixed $id) => (string) $id, $qb->getQuery()->getSingleColumnResult());
    }
}

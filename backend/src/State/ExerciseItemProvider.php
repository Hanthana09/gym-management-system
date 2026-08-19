<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Exercise;
use App\Repository\ExerciseRepository;

/** setly-phase-exercise-media.md §5: GET /exercises/{id} — single PK lookup, no caching needed. */
final class ExerciseItemProvider implements ProviderInterface
{
    public function __construct(private readonly ExerciseRepository $exercises)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Exercise
    {
        return $this->exercises->find($uriVariables['id'] ?? null);
    }
}

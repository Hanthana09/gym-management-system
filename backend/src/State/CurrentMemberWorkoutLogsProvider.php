<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Entity\WorkoutLog;
use App\Repository\MemberProfileRepository;
use App\Repository\WorkoutLogRepository;
use Symfony\Bundle\SecurityBundle\Security;

/** Resolves §7's `GET /members/me/workouts` — always the calling Member's own logs, no `{id}` in the path. */
final class CurrentMemberWorkoutLogsProvider implements ProviderInterface
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly WorkoutLogRepository $workoutLogs,
        private readonly Security $security,
    ) {
    }

    /** @return WorkoutLog[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $member = $this->memberProfiles->findOneByUser($user);

        return $member !== null ? $this->workoutLogs->findBy(['member' => $member], ['date' => 'DESC']) : [];
    }
}

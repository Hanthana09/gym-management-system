<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Repository\MemberProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves "me" for §7's `/members/me/...` GETs (membership, workouts,
 * body-metrics) — none of these carry an `{id}`, they're always the
 * calling Member's own profile. Same `findOneByUser()` lookup
 * MemberController/BodyMetricController etc. already use for this.
 */
final class CurrentMemberProfileProvider implements ProviderInterface
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?MemberProfile
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->memberProfiles->findOneByUser($user);
    }
}

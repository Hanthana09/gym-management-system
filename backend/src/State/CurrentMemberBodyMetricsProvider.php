<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\BodyMetric;
use App\Entity\User;
use App\Repository\BodyMetricRepository;
use App\Repository\MemberProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;

/** Resolves §7's `GET /members/me/body-metrics` — always the calling Member's own entries, no `{id}` in the path. */
final class CurrentMemberBodyMetricsProvider implements ProviderInterface
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly BodyMetricRepository $bodyMetrics,
        private readonly Security $security,
    ) {
    }

    /** @return BodyMetric[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $member = $this->memberProfiles->findOneByUser($user);

        return $member !== null ? $this->bodyMetrics->findBy(['member' => $member], ['date' => 'DESC']) : [];
    }
}

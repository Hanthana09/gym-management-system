<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Membership;
use App\Entity\User;
use App\Membership\MembershipService;
use App\Repository\MemberProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves §7's `GET /members/me/membership` — no `{id}`, always the
 * calling Member's own (most recent, lazily-expired-if-stale) membership.
 * Reuses MembershipService::getMembershipForMember() directly, the same
 * call MemberController's own "My membership" view already makes, so the
 * lazy-expiry behavior stays identical rather than being re-derived here.
 */
final class CurrentMembershipProvider implements ProviderInterface
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly MembershipService $memberships,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Membership
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $member = $this->memberProfiles->findOneByUser($user);

        return $member !== null ? $this->memberships->getMembershipForMember($member) : null;
    }
}

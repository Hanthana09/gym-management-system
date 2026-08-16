<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Invitation\InvitationService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves §7's `GET /invitations/me` — matched by the calling user's
 * identity (user_id/email/phone, InvitationVoter::RESPOND's own logic),
 * not an `{id}`. Reuses InvitationService::listForUser() directly — the
 * exact call InvitationController::mine() already makes — rather than
 * re-deriving the expired-invitation lazy-update logic here.
 *
 * @implements ProviderInterface<\App\Entity\Invitation>
 */
final class CurrentUserInvitationsProvider implements ProviderInterface
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->invitations->listForUser($user);
    }
}

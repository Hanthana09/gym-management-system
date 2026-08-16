<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;

/** Resolves §7's `GET /notifications` — "any authenticated user, scoped to self," no `{id}` in the path. */
final class CurrentUserNotificationsProvider implements ProviderInterface
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Security $security,
    ) {
    }

    /** @return Notification[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->findForUser($user) : [];
    }
}

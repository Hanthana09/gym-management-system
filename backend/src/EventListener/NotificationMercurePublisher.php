<?php

namespace App\EventListener;

use App\Entity\User;
use App\Event\NotificationCreatedEvent;
use App\Repository\NotificationRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Roadmap Phase 7 Definition of Done: "appears in-app within a few
 * seconds via Mercure" — the live in-app badge. Same pattern as
 * InvitationMercurePublisher/PtSessionMercurePublisher: a minimal payload
 * (just the new unread count) published non-private, per-user topic.
 */
class NotificationMercurePublisher implements EventSubscriberInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [NotificationCreatedEvent::NAME => 'onNotificationCreated'];
    }

    public function onNotificationCreated(NotificationCreatedEvent $event): void
    {
        $user = $event->getNotification()->getUser();

        $this->hub->publish(new Update(
            self::topicFor($user),
            json_encode(['unreadCount' => $this->notifications->countUnreadForUser($user)], JSON_THROW_ON_ERROR),
        ));
    }

    public static function topicFor(User $user): string
    {
        return sprintf('user/%s/notifications', $user->getId());
    }
}

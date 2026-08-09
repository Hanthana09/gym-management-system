<?php

namespace App\Event;

use App\Entity\Notification;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired whenever NotificationService::notify() creates a row — the
 * live-badge Mercure publish (roadmap Phase 7 Definition of Done) listens
 * to this separately, the same "service persists, a dedicated listener
 * handles the side-effect" split used everywhere else in this codebase
 * (InvitationMercurePublisher, PtSessionMercurePublisher).
 */
final class NotificationCreatedEvent extends Event
{
    public const NAME = 'notification.created';

    public function __construct(private readonly Notification $notification)
    {
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }
}

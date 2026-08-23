<?php

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Event\NotificationCreatedEvent;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.6: the single write path every other module's event
 * subscriber (and AnnouncementService) goes through to fan out a
 * notification — in-app row created synchronously (so it's immediately
 * visible via GET /api/v1/notifications and the live badge), email
 * dispatched asynchronously via Messenger so a slow provider never
 * blocks the request that triggered it.
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function notify(User $user, NotificationType $type, string $title, string $body, ?UserRole $sourceRole = null): Notification
    {
        $notification = new Notification($user, $title, $body, $type, $sourceRole);
        $this->em->persist($notification);
        $this->em->flush();

        $this->dispatcher->dispatch(new NotificationCreatedEvent($notification), NotificationCreatedEvent::NAME);
        $this->bus->dispatch(new SendNotificationEmailMessage((string) $notification->getId()));
        // roadmap Phase 15.3: the ONE touch point this phase is allowed to
        // change — every event-emitting module (Membership, Attendance, PT
        // sessions) still just calls notify() exactly as before. Eligibility
        // (opt-in + has a phone) is decided inside the handler, not here —
        // same division as the email dispatch above.
        $this->bus->dispatch(new SendNotificationWhatsAppMessage((string) $notification->getId()));

        return $notification;
    }

    public function markRead(Notification $notification): void
    {
        $notification->markRead();
        $this->em->flush();
    }

    /** gym-management-dashboard-redesign.md Phase 0: POST /api/v1/notifications/mark-all-read. */
    public function markAllReadForUser(User $user): void
    {
        $this->notifications->markAllReadForUser($user);
    }
}

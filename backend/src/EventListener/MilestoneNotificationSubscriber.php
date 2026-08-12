<?php

namespace App\EventListener;

use App\Enum\NotificationType;
use App\Event\MemberMilestoneReachedEvent;
use App\Notification\NotificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * roadmap Phase 9.3: "the Notification module (Phase 7) should pick this
 * up with zero changes to Phase 7's code." This is that pickup — a
 * brand-new file, not an edit to DomainEventNotificationSubscriber.php
 * (Phase 7). It calls NotificationService::notify() exactly the way
 * AnnouncementService (also Phase 7, but a different caller) already
 * does — NotificationService is the actual reusable "Notification
 * module" surface; DomainEventNotificationSubscriber is just one client
 * of it, not the only one, so a second, independent client needs no
 * changes there.
 *
 * NotificationType has no dedicated "milestone" case — architecture doc
 * §5.1 fixes the enum at booking|billing|announcement|system, and adding
 * a fifth would mean touching that shared enum for a single new caller.
 * `system` already fits a self-detected achievement notification
 * reasonably well, so this reuses it rather than extending the enum.
 */
class MilestoneNotificationSubscriber implements EventSubscriberInterface
{
    public const MILESTONE_TITLE = 'Milestone reached!';

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [MemberMilestoneReachedEvent::NAME => 'onMilestoneReached'];
    }

    public function onMilestoneReached(MemberMilestoneReachedEvent $event): void
    {
        $this->notifications->notify(
            $event->getMember()->getUser(),
            NotificationType::SYSTEM,
            self::MILESTONE_TITLE,
            sprintf('You hit a %d-day check-in streak. Keep it going!', $event->getValue()),
            // No human actor for a self-detected achievement — sourceRole stays null (same as membership.expiring).
        );
    }
}

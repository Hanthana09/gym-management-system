<?php

namespace App\EventListener;

use App\Entity\Gym;
use App\Event\AttendanceCheckedInEvent;
use App\Repository\AttendanceLogRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * roadmap Phase 5 Definition of Done: "the Owner's counter updates
 * without a manual refresh." Publishes the authoritative today-so-far
 * count (not a bare "+1" signal) so a freshly-opened dashboard and an
 * already-open one never disagree. Same non-private-update simplification
 * as Phase 3's InvitationMercurePublisher — no subscriber JWT needed.
 */
class AttendanceMercurePublisher implements EventSubscriberInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly AttendanceLogRepository $attendanceLogs,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [AttendanceCheckedInEvent::NAME => 'onCheckedIn'];
    }

    public function onCheckedIn(AttendanceCheckedInEvent $event): void
    {
        $count = $this->attendanceLogs->countSince(new \DateTimeImmutable('today'));

        $this->hub->publish(new Update(
            self::topicFor($event->getGym()),
            json_encode(['count' => $count], JSON_THROW_ON_ERROR),
        ));
    }

    public static function topicFor(Gym $gym): string
    {
        return sprintf('gym/%s/attendance', $gym->getId());
    }
}

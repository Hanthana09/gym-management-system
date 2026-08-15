<?php

namespace App\EventListener;

use App\Entity\AttendanceLog;
use App\Entity\Gym;
use App\Event\AttendanceCheckedInEvent;
use App\Event\AttendanceCheckedOutEvent;
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
 *
 * Check-in-timer feature: also publishes a per-member `attendance/{id}`
 * topic on both check-in and check-out, so a Member's top-bar timer stays
 * live (starts, and freezes on checkout from another device/tab) without
 * a manual refresh — same non-private-update tradeoff as the gym-wide
 * counter above; the topic name (a UUID) is the only thing standing
 * between another Member and this data, same as every other topic in this
 * app today. A real per-subscriber JWT ACL is a separate, cross-cutting
 * piece of Mercure infrastructure this app doesn't have yet — the REST
 * endpoint this feature also added (AttendanceController::activeAttendance)
 * is where the actual 403 is enforced.
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
        return [
            AttendanceCheckedInEvent::NAME => 'onCheckedIn',
            AttendanceCheckedOutEvent::NAME => 'onCheckedOut',
        ];
    }

    public function onCheckedIn(AttendanceCheckedInEvent $event): void
    {
        $count = $this->attendanceLogs->countSince(new \DateTimeImmutable('today'));

        $this->hub->publish(new Update(
            self::topicFor($event->getGym()),
            json_encode(['count' => $count], JSON_THROW_ON_ERROR),
        ));

        $log = $event->getLog();
        $this->hub->publish(new Update(
            self::memberTopicFor($log),
            json_encode(['checkInTime' => $log->getCheckIn()->format(\DateTimeInterface::ATOM), 'checkOutTime' => null], JSON_THROW_ON_ERROR),
        ));
    }

    public function onCheckedOut(AttendanceCheckedOutEvent $event): void
    {
        $log = $event->getLog();

        $this->hub->publish(new Update(
            self::memberTopicFor($log),
            json_encode([
                'checkInTime' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
                'checkOutTime' => $log->getCheckOut()?->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public static function topicFor(Gym $gym): string
    {
        return sprintf('gym/%s/attendance', $gym->getId());
    }

    public static function memberTopicFor(AttendanceLog $log): string
    {
        return sprintf('attendance/%s', $log->getMember()->getUser()->getId());
    }
}

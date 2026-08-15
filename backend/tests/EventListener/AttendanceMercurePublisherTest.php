<?php

namespace App\Tests\EventListener;

use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\AttendanceCheckedInEvent;
use App\Event\AttendanceCheckedOutEvent;
use App\EventListener\AttendanceMercurePublisher;
use App\Repository\AttendanceLogRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Check-in-timer feature: this is the one piece with no existing test
 * convention to follow (no other test in this codebase inspects a
 * published Mercure Update directly — PtSessionControllerTest etc. only
 * assert the domain event was dispatched, since HubInterface isn't
 * mocked/exposed in the WebTestCase container). A bare unit test (stubbed
 * HubInterface, no container) proves the one thing most likely to be
 * silently wrong: the exact topic name and payload shape the frontend's
 * useActiveAttendance hook depends on.
 */
final class AttendanceMercurePublisherTest extends TestCase
{
    private function member(): MemberProfile
    {
        $user = new User('Mia Member', 'mia@example.com', null, UserRole::MEMBER, UserStatus::ACTIVE);

        return new MemberProfile($user);
    }

    private function branch(): Branch
    {
        $owner = new User('Olivia Owner', 'olivia@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        return new Branch($gym, 'Main', '1 Main St', isPrimary: true);
    }

    public function test_check_in_publishes_to_the_members_attendance_topic_with_null_check_out_time(): void
    {
        $member = $this->member();
        $branch = $this->branch();
        $checkIn = new \DateTimeImmutable('2026-08-14T09:00:00+00:00');
        $log = new AttendanceLog($member, $branch, $checkIn, CheckInMethod::MANUAL);

        $hub = $this->createStub(HubInterface::class);
        $captured = [];
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured[] = $update;

            return 'id';
        });

        $publisher = new AttendanceMercurePublisher($hub, $this->createStub(AttendanceLogRepository::class));
        $publisher->onCheckedIn(new AttendanceCheckedInEvent($log, $branch->getGym()));

        $memberUpdate = self::findUpdateForTopic($captured, 'attendance/' . $member->getUser()->getId());
        self::assertNotNull($memberUpdate, 'expected a publish to the attendance/{memberId} topic');

        $payload = json_decode($memberUpdate->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($checkIn->format(\DateTimeInterface::ATOM), $payload['checkInTime']);
        self::assertNull($payload['checkOutTime']);
    }

    public function test_check_out_publishes_to_the_same_topic_with_both_times_set(): void
    {
        $member = $this->member();
        $branch = $this->branch();
        $checkIn = new \DateTimeImmutable('2026-08-14T09:00:00+00:00');
        $checkOut = new \DateTimeImmutable('2026-08-14T10:15:30+00:00');
        $log = new AttendanceLog($member, $branch, $checkIn, CheckInMethod::MANUAL);
        $log->checkOut($checkOut);

        $hub = $this->createStub(HubInterface::class);
        $captured = [];
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured[] = $update;

            return 'id';
        });

        $publisher = new AttendanceMercurePublisher($hub, $this->createStub(AttendanceLogRepository::class));
        $publisher->onCheckedOut(new AttendanceCheckedOutEvent($log));

        self::assertCount(1, $captured, 'check-out never touches the gym-wide counter topic, only the per-member one');
        $payload = json_decode($captured[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['attendance/' . $member->getUser()->getId()], $captured[0]->getTopics());
        self::assertSame($checkIn->format(\DateTimeInterface::ATOM), $payload['checkInTime']);
        self::assertSame($checkOut->format(\DateTimeInterface::ATOM), $payload['checkOutTime']);
    }

    /** @param Update[] $updates */
    private static function findUpdateForTopic(array $updates, string $topic): ?Update
    {
        foreach ($updates as $update) {
            if (in_array($topic, $update->getTopics(), true)) {
                return $update;
            }
        }

        return null;
    }
}

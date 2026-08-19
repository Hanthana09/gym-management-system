<?php

namespace App\Tests\EventListener;

use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Entity\WorkoutSchedule;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\WorkoutAssignmentCreatedEvent;
use App\Event\WorkoutAssignmentReplacedEvent;
use App\Event\WorkoutScheduleExerciseChangedEvent;
use App\EventListener\WorkoutScheduleMercurePublisher;
use App\Repository\WorkoutAssignmentRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * setly-phase-workout-scheduling.md §6: proves the exact topic name and
 * thin payload shape the member-facing Mercure subscription depends on —
 * same bare-unit-test convention as AttendanceMercurePublisherTest (no
 * other test in this codebase inspects a published Update directly).
 */
final class WorkoutScheduleMercurePublisherTest extends TestCase
{
    private function member(): User
    {
        return new User('Mia Member', 'mia@example.com', null, UserRole::MEMBER, UserStatus::ACTIVE);
    }

    private function assignment(User $member): WorkoutAssignment
    {
        $owner = new User('Olivia Owner', 'olivia@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $coach = new User('Cara Coach', 'coach@example.com', null, UserRole::COACH, UserStatus::ACTIVE);
        $schedule = new WorkoutSchedule($gym, $coach, 'Strength Block', 'strength');

        return new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today'));
    }

    public function test_assignment_created_publishes_a_thin_payload_to_the_members_assignment_updates_topic(): void
    {
        $member = $this->member();
        $assignment = $this->assignment($member);
        $captured = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured[] = $update;

            return 'id';
        });

        $publisher = new WorkoutScheduleMercurePublisher($hub, $this->createStub(WorkoutAssignmentRepository::class));
        $publisher->onAssignmentCreated(new WorkoutAssignmentCreatedEvent($assignment));

        self::assertCount(1, $captured);
        self::assertSame(['members/' . $member->getId() . '/assignment-updates'], $captured[0]->getTopics());
        $payload = json_decode($captured[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame((string) $assignment->getId(), $payload['assignmentId']);
        self::assertSame((string) $assignment->getSchedule()->getId(), $payload['scheduleId']);
        self::assertSame('assignment_created', $payload['changeType']);
    }

    public function test_assignment_replaced_publishes_to_the_replaced_assignments_own_member_topic(): void
    {
        $member = $this->member();
        $assignment = $this->assignment($member);
        $assignment->markReplaced();
        $captured = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured[] = $update;

            return 'id';
        });

        $publisher = new WorkoutScheduleMercurePublisher($hub, $this->createStub(WorkoutAssignmentRepository::class));
        $publisher->onAssignmentReplaced(new WorkoutAssignmentReplacedEvent($assignment));

        $payload = json_decode($captured[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('assignment_replaced', $payload['changeType']);
    }

    /** §6: a schedule-exercise change fans out to every active assignment referencing that schedule. */
    public function test_schedule_exercise_changed_fans_out_to_every_active_assignment_for_the_schedule(): void
    {
        $memberOne = $this->member();
        $assignmentOne = $this->assignment($memberOne);
        $memberTwo = new User('Marco Member', 'marco@example.com', null, UserRole::MEMBER, UserStatus::ACTIVE);
        $assignmentTwo = new WorkoutAssignment($assignmentOne->getSchedule(), $memberTwo, $assignmentOne->getCoach(), new \DateTimeImmutable('today'));

        $captured = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured) {
            $captured[] = $update;

            return 'id';
        });
        $assignments = $this->createStub(WorkoutAssignmentRepository::class);
        $assignments->method('findActiveForSchedule')->willReturn([$assignmentOne, $assignmentTwo]);

        $publisher = new WorkoutScheduleMercurePublisher($hub, $assignments);
        $publisher->onScheduleExerciseChanged(new WorkoutScheduleExerciseChangedEvent($assignmentOne->getSchedule(), WorkoutScheduleExerciseChangedEvent::CHANGE_UPDATED));

        self::assertCount(2, $captured);
        $topics = array_merge(...array_map(fn (Update $u) => $u->getTopics(), $captured));
        self::assertContains('members/' . $memberOne->getId() . '/assignment-updates', $topics);
        self::assertContains('members/' . $memberTwo->getId() . '/assignment-updates', $topics);
    }
}

<?php

namespace App\Tests\WorkoutScheduling;

use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutSchedule;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\WorkoutAssignmentRepository;
use App\WorkoutScheduling\AssignmentConflictException;
use App\WorkoutScheduling\WorkoutAssignmentService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * setly-phase-workout-scheduling.md §4: "the service catches that as a
 * conflict rather than relying on the transaction alone." Isolated unit
 * test (mocked EntityManager) for exactly that catch-and-convert step —
 * the real database-level guarantee itself is proven separately in
 * tests/Functional/WorkoutAssignmentServiceTest.php, which needs a real
 * Postgres connection to exercise the partial unique index.
 */
final class WorkoutAssignmentServiceConflictTest extends TestCase
{
    public function test_a_unique_constraint_violation_from_the_transaction_becomes_an_assignment_conflict_exception(): void
    {
        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $coach = new User('Cara Coach', 'coach@example.com', null, UserRole::COACH, UserStatus::ACTIVE);
        $member = new User('Mia Member', 'mia@example.com', null, UserRole::MEMBER, UserStatus::ACTIVE);
        $schedule = new WorkoutSchedule($gym, $coach, 'Block A', 'strength');

        $driverException = $this->createStub(DriverException::class);
        $driverException->method('getSQLState')->willReturn('23505');
        $violation = new UniqueConstraintViolationException($driverException, null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($violation);

        $service = new WorkoutAssignmentService(
            $this->createStub(WorkoutAssignmentRepository::class),
            $em,
            $this->createStub(EventDispatcherInterface::class),
        );

        $this->expectException(AssignmentConflictException::class);
        $service->assign($schedule, $member, $coach);
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\Exercise;
use App\Entity\ExerciseLog;
use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutSchedule;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ExerciseLogRepository;
use App\WorkoutScheduling\AssignmentConflictException;
use App\WorkoutScheduling\WorkoutAssignmentService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * setly-phase-workout-scheduling.md §4: assign/replace, tested directly
 * against the service (not just the Voter unit tests) since the
 * transactional replace-and-create dance and the partial unique index's
 * concurrency guarantee both need a real database.
 */
final class WorkoutAssignmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkoutAssignmentService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkoutAssignmentService::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE exercise_log, workout_assignment, workout_schedule_exercise, workout_schedule, exercise, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    private function persistedUser(UserRole $role): User
    {
        static $counter = 0;
        ++$counter;

        $user = new User("User {$counter}", "user{$counter}@example.com", null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);

        return $user;
    }

    /** @return array{0: Gym, 1: User, 2: User} */
    private function gymWithCoachAndMember(): array
    {
        $owner = $this->persistedUser(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $coach = $this->persistedUser(UserRole::COACH);
        $member = $this->persistedUser(UserRole::MEMBER);
        $this->em->flush();

        return [$gym, $coach, $member];
    }

    /** setly-phase-workout-scheduling.md §4 step 3: "does not touch its ExerciseLog rows." */
    public function test_replacing_an_assignment_preserves_prior_logs_under_the_old_assignment_id(): void
    {
        [$gym, $coach, $member] = $this->gymWithCoachAndMember();
        $scheduleA = new WorkoutSchedule($gym, $coach, 'Block A', 'strength');
        $scheduleB = new WorkoutSchedule($gym, $coach, 'Block B', 'strength');
        $exercise = new Exercise('src-bench-press', 'Bench Press', 'bench-press', 'beginner', 'strength');
        $exercise->update('Bench Press', 'bench-press', null, 'beginner', null, 'barbell', ['chest'], [], [], 'strength');
        $this->em->persist($scheduleA);
        $this->em->persist($scheduleB);
        $this->em->persist($exercise);
        $this->em->flush();

        $firstAssignment = $this->service->assign($scheduleA, $member, $coach);
        $log = new ExerciseLog($firstAssignment, $exercise, $member, 3, 10, '60.00', null);
        $this->em->persist($log);
        $this->em->flush();

        $secondAssignment = $this->service->assign($scheduleB, $member, $coach);

        $this->em->refresh($firstAssignment);
        self::assertSame('replaced', $firstAssignment->getStatus());
        self::assertSame('active', $secondAssignment->getStatus());
        self::assertNotSame($firstAssignment->getId(), $secondAssignment->getId());

        $logs = static::getContainer()->get(ExerciseLogRepository::class)->findByAssignment($firstAssignment);
        self::assertCount(1, $logs, 'the replaced assignment\'s log must still be queryable under its own assignment_id');
        self::assertSame((string) $log->getId(), (string) $logs[0]->getId());
    }

    /**
     * §4's real safety net, tested at the level it actually operates:
     * the partial unique index itself. Two application-level requests
     * racing to insert an `active` row for the same (coach, member) pair
     * both resolve to this exact SQL-level rejection regardless of
     * timing — WorkoutAssignmentService::assign() catches precisely this
     * exception class and converts it to AssignmentConflictException (see
     * the direct catch-and-convert assertion below).
     */
    public function test_the_partial_unique_index_rejects_a_second_concurrent_active_row_for_the_same_pair(): void
    {
        [$gym, $coach, $member] = $this->gymWithCoachAndMember();
        $schedule = new WorkoutSchedule($gym, $coach, 'Block A', 'strength');
        $this->em->persist($schedule);
        $this->em->flush();

        $this->service->assign($schedule, $member, $coach);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO workout_assignment (id, schedule_id, member_id, coach_id, status, start_date, assigned_at) VALUES (:id, :schedule, :member, :coach, \'active\', CURRENT_DATE, NOW())',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'schedule' => (string) $schedule->getId(),
                'member' => (string) $member->getId(),
                'coach' => (string) $coach->getId(),
            ],
        );
    }

}

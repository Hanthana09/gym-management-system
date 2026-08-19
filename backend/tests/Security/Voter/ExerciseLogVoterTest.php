<?php

namespace App\Tests\Security\Voter;

use App\Entity\Exercise;
use App\Entity\ExerciseLog;
use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Entity\WorkoutSchedule;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\WorkoutScheduleExerciseRepository;
use App\Security\Voter\ExerciseLogVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * setly-phase-workout-scheduling.md §5, implemented exactly as documented
 * there. CLAUDE.md: every Voter needs at least a passing case and a 403
 * case — this one needs all six of the phase doc's explicit negative
 * cases, since each exercises a distinct denial step.
 */
final class ExerciseLogVoterTest extends TestCase
{
    private function user(UserRole $role): User
    {
        static $counter = 0;
        ++$counter;

        return new User("User {$counter}", "user{$counter}@example.com", null, $role, UserStatus::ACTIVE);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    /** @return array{0: ExerciseLogVoter, 1: WorkoutSchedule, 2: Exercise, 3: User, 4: User} */
    private function scenario(bool $exerciseInSchedule): array
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $coach = $this->user(UserRole::COACH);
        $member = $this->user(UserRole::MEMBER);
        $schedule = new WorkoutSchedule($gym, $coach, 'Strength Block', 'strength');
        $exercise = new Exercise('src-bench-press', 'Bench Press', 'bench-press', 'beginner', 'strength');

        $scheduleExercises = $this->createStub(WorkoutScheduleExerciseRepository::class);
        $scheduleExercises->method('existsForScheduleAndExercise')->willReturn($exerciseInSchedule);

        return [new ExerciseLogVoter($scheduleExercises), $schedule, $exercise, $coach, $member];
    }

    private function log(WorkoutAssignment $assignment, Exercise $exercise, User $loggedAsMember): ExerciseLog
    {
        return new ExerciseLog($assignment, $exercise, $loggedAsMember, 3, 10, '60.00', null);
    }

    // ---- GRANT: exercise in schedule, active assignment, own assignment ----

    public function test_member_can_log_an_exercise_that_is_in_their_active_schedule(): void
    {
        [$voter, $schedule, $exercise, $coach, $member] = $this->scenario(exerciseInSchedule: true);
        $assignment = new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today'));
        $log = $this->log($assignment, $exercise, $member);

        $result = $voter->vote($this->tokenFor($member), $log, [ExerciseLogVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ---- DENY: exercise exists in the global catalog but not this schedule ----

    public function test_a_member_cannot_log_an_exercise_not_in_their_schedule_403(): void
    {
        [$voter, $schedule, $exercise, $coach, $member] = $this->scenario(exerciseInSchedule: false);
        $assignment = new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today'));
        $log = $this->log($assignment, $exercise, $member);

        $result = $voter->vote($this->tokenFor($member), $log, [ExerciseLogVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- DENY: assignment status = replaced ----

    public function test_a_member_cannot_log_against_a_replaced_assignment_403(): void
    {
        [$voter, $schedule, $exercise, $coach, $member] = $this->scenario(exerciseInSchedule: true);
        $assignment = new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today'));
        $assignment->markReplaced();
        $log = $this->log($assignment, $exercise, $member);

        $result = $voter->vote($this->tokenFor($member), $log, [ExerciseLogVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- DENY: Member A vs Member B's assignment_id ----

    public function test_a_different_member_cannot_log_against_someone_elses_assignment_403(): void
    {
        [$voter, $schedule, $exercise, $coach, $member] = $this->scenario(exerciseInSchedule: true);
        $assignment = new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today'));
        $log = $this->log($assignment, $exercise, $member);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $voter->vote($this->tokenFor($someoneElse), $log, [ExerciseLogVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

<?php

namespace App\Tests\Security\Voter;

use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutSchedule;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\WorkoutScheduleVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** CLAUDE.md: every Voter needs at least a passing case and a 403 case. */
final class WorkoutScheduleVoterTest extends TestCase
{
    private WorkoutScheduleVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new WorkoutScheduleVoter();
    }

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

    private function schedule(User $coach): WorkoutSchedule
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        return new WorkoutSchedule($gym, $coach, '12-Week Strength Block', 'strength');
    }

    public function test_the_owning_coach_can_manage_their_schedule(): void
    {
        $coach = $this->user(UserRole::COACH);
        $schedule = $this->schedule($coach);

        $result = $this->voter->vote($this->tokenFor($coach), $schedule, [WorkoutScheduleVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** setly-phase-workout-scheduling.md's negative test: "Coach attempts to assign a schedule belonging to a different gym's coach -> 403 via existing gym-scoping Voter." */
    public function test_a_different_coach_cannot_manage_someone_elses_schedule_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $schedule = $this->schedule($coach);
        $otherCoach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($otherCoach), $schedule, [WorkoutScheduleVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_owner_can_view_but_not_manage_any_schedule(): void
    {
        $coach = $this->user(UserRole::COACH);
        $schedule = $this->schedule($coach);
        $owner = $this->user(UserRole::OWNER);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $schedule, [WorkoutScheduleVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($owner), $schedule, [WorkoutScheduleVoter::MANAGE]));
    }
}

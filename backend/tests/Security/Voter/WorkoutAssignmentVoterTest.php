<?php

namespace App\Tests\Security\Voter;

use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Entity\WorkoutSchedule;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\WorkoutAssignmentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** CLAUDE.md: every Voter needs at least a passing case and a 403 case. */
final class WorkoutAssignmentVoterTest extends TestCase
{
    private WorkoutAssignmentVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new WorkoutAssignmentVoter();
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

    /** @return array{0: WorkoutAssignment, 1: User, 2: User} */
    private function assignment(): array
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $coach = $this->user(UserRole::COACH);
        $member = $this->user(UserRole::MEMBER);
        $schedule = new WorkoutSchedule($gym, $coach, 'Strength Block', 'strength');

        return [new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today')), $coach, $member];
    }

    public function test_the_assigned_member_can_view_their_own_assignment(): void
    {
        [$assignment, , $member] = $this->assignment();

        $result = $this->voter->vote($this->tokenFor($member), $assignment, [WorkoutAssignmentVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** setly-phase-workout-scheduling.md's negative test: "Member A attempts to log against Member B's assignment_id -> 403" — same identity check backs the scoped-exercises/logs endpoints. */
    public function test_a_different_member_cannot_view_someone_elses_assignment_403(): void
    {
        [$assignment] = $this->assignment();
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $assignment, [WorkoutAssignmentVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_the_owning_coach_can_view_the_assignment(): void
    {
        [$assignment, $coach] = $this->assignment();

        $result = $this->voter->vote($this->tokenFor($coach), $assignment, [WorkoutAssignmentVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}

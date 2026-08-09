<?php

namespace App\Tests\Security\Voter;

use App\Entity\BodyMetric;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Entity\WorkoutLog;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\PersonalTrackingVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * PersonalTrackingVoter is copied verbatim from architecture doc §9.1 —
 * this test proves the copy behaves as documented. The Coach-403 cases
 * matter more here than in any other Voter in this codebase: there's no
 * role branch at all to get subtly wrong, so the risk isn't "the Coach
 * logic is wrong" (there is none) but "someone adds a Coach branch later
 * without realizing this was a deliberate open decision, not an
 * oversight" — these tests are what would catch that regression.
 */
final class PersonalTrackingVoterTest extends TestCase
{
    private PersonalTrackingVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PersonalTrackingVoter();
    }

    private function user(UserRole $role): User
    {
        static $counter = 0;
        ++$counter;

        return new User("User {$counter}", "user{$counter}@example.com", "+1555000{$counter}", $role, UserStatus::ACTIVE);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    // ---- WorkoutLog -----------------------------------------------------

    public function test_member_can_manage_their_own_workout_log(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $log = new WorkoutLog(new MemberProfile($memberUser), new \DateTimeImmutable('today'), 'Run', 30);

        $result = $this->voter->vote($this->tokenFor($memberUser), $log, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_manage_someone_elses_workout_log_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $log = new WorkoutLog(new MemberProfile($memberUser), new \DateTimeImmutable('today'), 'Run', 30);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $log, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * The specific case the task calls out: "explicitly test that a Coach
     * requesting a client's WorkoutLog ... gets rejected." No amount of
     * being the member's actual coach changes this — there's no
     * hasCoach()-style check anywhere in this Voter, by design.
     */
    public function test_coach_cannot_access_a_clients_workout_log_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $log = new WorkoutLog(new MemberProfile($memberUser), new \DateTimeImmutable('today'), 'Run', 30);
        $coach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($coach), $log, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_owner_cannot_access_a_members_workout_log_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $log = new WorkoutLog(new MemberProfile($memberUser), new \DateTimeImmutable('today'), 'Run', 30);
        $owner = $this->user(UserRole::OWNER);

        $result = $this->voter->vote($this->tokenFor($owner), $log, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- BodyMetric ------------------------------------------------------

    public function test_member_can_manage_their_own_body_metric(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $metric = new BodyMetric(new MemberProfile($memberUser), new \DateTimeImmutable('today'), '70.50');

        $result = $this->voter->vote($this->tokenFor($memberUser), $metric, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** The task's named case, repeated for BodyMetric specifically (not just WorkoutLog). */
    public function test_coach_cannot_access_a_clients_body_metric_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $metric = new BodyMetric(new MemberProfile($memberUser), new \DateTimeImmutable('today'), '70.50');
        $coach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($coach), $metric, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_a_different_member_cannot_access_someone_elses_body_metric_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $metric = new BodyMetric(new MemberProfile($memberUser), new \DateTimeImmutable('today'), '70.50');
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $metric, [PersonalTrackingVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

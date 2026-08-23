<?php

namespace App\Tests\Security\Voter;

use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\DashboardVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * gym-management-dashboard-redesign.md §6: Staff→owner, Coach→staff,
 * Member→coach/staff must all be 403 — one Voter, one attribute per role.
 */
final class DashboardVoterTest extends TestCase
{
    private DashboardVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new DashboardVoter();
    }

    private function user(UserRole $role): User
    {
        ++self::$counter;

        return new User("User " . self::$counter, "user" . self::$counter . '@example.com', '+1555000' . self::$counter, $role, UserStatus::ACTIVE);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    public function test_owner_can_view_their_own_gyms_dashboard(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $gym, [DashboardVoter::VIEW_OWNER]));
    }

    public function test_a_different_owner_cannot_view_someone_elses_gym_dashboard_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $otherOwner = $this->user(UserRole::OWNER);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($otherOwner), $gym, [DashboardVoter::VIEW_OWNER]));
    }

    public function test_staff_cannot_view_the_owner_dashboard_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $staff = $this->user(UserRole::STAFF);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($staff), $gym, [DashboardVoter::VIEW_OWNER]));
    }

    public function test_staff_can_view_the_staff_dashboard(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $staff = $this->user(UserRole::STAFF);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($staff), $gym, [DashboardVoter::VIEW_STAFF]));
    }

    public function test_coach_cannot_view_the_staff_dashboard_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $coach = $this->user(UserRole::COACH);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $gym, [DashboardVoter::VIEW_STAFF]));
    }

    public function test_coach_can_view_the_coach_dashboard(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $coach = $this->user(UserRole::COACH);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($coach), $gym, [DashboardVoter::VIEW_COACH]));
    }

    public function test_member_cannot_view_the_coach_dashboard_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $member = $this->user(UserRole::MEMBER);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $gym, [DashboardVoter::VIEW_COACH]));
    }

    public function test_member_cannot_view_the_staff_dashboard_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $member = $this->user(UserRole::MEMBER);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $gym, [DashboardVoter::VIEW_STAFF]));
    }

    public function test_member_can_view_the_member_dashboard(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $member = $this->user(UserRole::MEMBER);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($member), $gym, [DashboardVoter::VIEW_MEMBER]));
    }
}

<?php

namespace App\Tests\Security\Voter;

use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\ReportVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * ReportVoter is copied verbatim from architecture doc §9.1 (no
 * single-gym adaptation needed — Gym::getOwner() is real). roadmap
 * Phase 11 calls out EXPORT's pass case (Owner, own gym) + 403 case
 * (a different Owner, or a Coach/Member) specifically.
 */
final class ReportVoterTest extends TestCase
{
    private ReportVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new ReportVoter();
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

    // ---- VIEW ---------------------------------------------------------

    public function test_owner_can_view_their_own_gyms_reports(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        $result = $this->voter->vote($this->tokenFor($owner), $gym, [ReportVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_owner_cannot_view_someone_elses_gym_reports_403(): void
    {
        $gym = new Gym('Test Gym', '1 Main St', $this->user(UserRole::OWNER));
        $otherOwner = $this->user(UserRole::OWNER);

        $result = $this->voter->vote($this->tokenFor($otherOwner), $gym, [ReportVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_view_reports_403(): void
    {
        $gym = new Gym('Test Gym', '1 Main St', $this->user(UserRole::OWNER));
        $coach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($coach), $gym, [ReportVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_cannot_view_reports_403(): void
    {
        $gym = new Gym('Test Gym', '1 Main St', $this->user(UserRole::OWNER));
        $member = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($member), $gym, [ReportVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- EXPORT ---------------------------------------------------------

    public function test_owner_can_export_their_own_gyms_reports(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        $result = $this->voter->vote($this->tokenFor($owner), $gym, [ReportVoter::EXPORT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_owner_cannot_export_someone_elses_gym_reports_403(): void
    {
        $gym = new Gym('Test Gym', '1 Main St', $this->user(UserRole::OWNER));
        $otherOwner = $this->user(UserRole::OWNER);

        $result = $this->voter->vote($this->tokenFor($otherOwner), $gym, [ReportVoter::EXPORT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_export_reports_403(): void
    {
        $gym = new Gym('Test Gym', '1 Main St', $this->user(UserRole::OWNER));
        $coach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($coach), $gym, [ReportVoter::EXPORT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_cannot_export_reports_403(): void
    {
        $gym = new Gym('Test Gym', '1 Main St', $this->user(UserRole::OWNER));
        $member = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($member), $gym, [ReportVoter::EXPORT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

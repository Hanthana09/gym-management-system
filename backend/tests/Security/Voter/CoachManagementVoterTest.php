<?php

namespace App\Tests\Security\Voter;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\CoachManagementVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * CoachManagementVoter is copied from architecture doc §9.1, adapted only
 * to drop the non-existent User::getGym() call (the same single-gym
 * collapse every other Voter in this codebase uses).
 */
final class CoachManagementVoterTest extends TestCase
{
    private CoachManagementVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new CoachManagementVoter();
    }

    private function user(UserRole $role): User
    {
        ++self::$counter;

        return new User('User ' . self::$counter, 'user' . self::$counter . '@example.com', '+1555000' . self::$counter, $role, UserStatus::ACTIVE);
    }

    private function coachProfile(): CoachProfile
    {
        return new CoachProfile($this->user(UserRole::COACH));
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    public function test_owner_can_manage_a_coach(): void
    {
        $result = $this->voter->vote($this->tokenFor($this->user(UserRole::OWNER)), $this->coachProfile(), [CoachManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_coach_cannot_manage_a_coach_403(): void
    {
        $result = $this->voter->vote($this->tokenFor($this->user(UserRole::COACH)), $this->coachProfile(), [CoachManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_staff_cannot_manage_a_coach_403(): void
    {
        $result = $this->voter->vote($this->tokenFor($this->user(UserRole::STAFF)), $this->coachProfile(), [CoachManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_cannot_manage_a_coach_403(): void
    {
        $result = $this->voter->vote($this->tokenFor($this->user(UserRole::MEMBER)), $this->coachProfile(), [CoachManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** supports() only matches a CoachProfile subject. */
    public function test_does_not_support_a_non_coach_profile_subject(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $memberProfile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($owner), $memberProfile, [CoachManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}

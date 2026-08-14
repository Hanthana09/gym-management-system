<?php

namespace App\Tests\Security\Voter;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\MemberVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * MemberVoter is copied from architecture doc §9.1, adapted only to drop
 * the non-existent User::getGym() call (see the Voter's own docblock) —
 * this test proves the adapted copy behaves as documented.
 */
final class MemberVoterTest extends TestCase
{
    private MemberVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new MemberVoter();
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

    // ---- VIEW ---------------------------------------------------------

    public function test_owner_can_view_any_member(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_member_can_view_their_own_record(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_view_someone_elses_record_403(): void
    {
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** MemberProfile::hasCoach() is a still-undefined placeholder (Phase 6) that always returns false. */
    public function test_coach_cannot_view_a_member_before_coach_assignment_exists_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($coach), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** roadmap Phase 15.1: Staff gets read-only VIEW, gym-scoped same as Owner. */
    public function test_staff_can_view_any_member(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ---- MANAGE ---------------------------------------------------------

    public function test_owner_can_manage_any_member(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [MemberVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_member_cannot_manage_even_their_own_record_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $profile, [MemberVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_manage_a_member_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($coach), $profile, [MemberVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** functional requirements §11.2: Staff has "no edit/suspend/remove actions available." */
    public function test_staff_cannot_manage_a_member_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

<?php

namespace App\Tests\Security\Voter;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\AttendanceVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * AttendanceVoter is copied verbatim from architecture doc §9.1 — this
 * test proves the copy behaves as documented, not that the logic itself
 * needed writing.
 */
final class AttendanceVoterTest extends TestCase
{
    private AttendanceVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new AttendanceVoter();
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

    // ---- CHECK_IN ----------------------------------------------------------

    public function test_member_can_check_in_for_themselves(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $profile, [AttendanceVoter::CHECK_IN]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_owner_can_check_in_on_behalf_of_a_member_front_desk(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [AttendanceVoter::CHECK_IN]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * functional requirements §4.1-adjacent guarantee: a member must not
     * be able to check in as someone else, even knowing their profile.
     */
    public function test_a_different_member_cannot_check_in_for_someone_else_403(): void
    {
        $actualMemberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($actualMemberUser);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $profile, [AttendanceVoter::CHECK_IN]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_check_in_for_a_member_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($coach), $profile, [AttendanceVoter::CHECK_IN]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** roadmap Phase 15.1: Staff gets the same front-desk check-in capability as Owner. */
    public function test_staff_can_check_in_on_behalf_of_a_member_front_desk(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [AttendanceVoter::CHECK_IN]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ---- VIEW_ALL (Owner dashboard/reports) --------------------------------

    public function test_owner_can_view_all(): void
    {
        $owner = $this->user(UserRole::OWNER);

        $result = $this->voter->vote($this->tokenFor($owner), null, [AttendanceVoter::VIEW_ALL]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_non_owner_cannot_view_all_403(): void
    {
        $member = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($member), null, [AttendanceVoter::VIEW_ALL]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** functional requirements §11.2 / architecture doc §2: Staff is explicitly excluded from reports, even attendance's own VIEW_ALL. */
    public function test_staff_cannot_view_all_403(): void
    {
        $staff = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($staff), null, [AttendanceVoter::VIEW_ALL]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- VIEW (self, or Coach's own clients) -------------------------------

    public function test_member_can_view_their_own_attendance(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $profile, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_view_someone_elses_attendance_403(): void
    {
        $actualMemberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($actualMemberUser);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $profile, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * MemberProfile::hasCoach() is a Phase 6 placeholder that always
     * returns false (no coach-client relationship exists yet) — a Coach
     * must therefore be denied VIEW on every member for now.
     */
    public function test_coach_cannot_view_a_members_attendance_before_coach_assignment_exists_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($coach), $profile, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** roadmap Phase 15.1: Staff gets read-only VIEW, gym-scoped, like Owner (but never VIEW_ALL — see above). */
    public function test_staff_can_view_any_members_attendance(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}

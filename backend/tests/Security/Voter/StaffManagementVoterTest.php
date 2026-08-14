<?php

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\StaffManagementVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * StaffManagementVoter is new in Phase 15, copied from architecture doc
 * §9.1 (adapted only to drop the non-existent User::getGym() call, same
 * as every other Voter's single-gym collapse).
 */
final class StaffManagementVoterTest extends TestCase
{
    private StaffManagementVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new StaffManagementVoter();
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

    public function test_owner_can_manage_a_staff_account(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $staffAccount = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($owner), $staffAccount, [StaffManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_staff_cannot_manage_another_staff_account_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $staffAccount = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($staff), $staffAccount, [StaffManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_manage_a_staff_account_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $staffAccount = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($coach), $staffAccount, [StaffManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_cannot_manage_a_staff_account_403(): void
    {
        $member = $this->user(UserRole::MEMBER);
        $staffAccount = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($member), $staffAccount, [StaffManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** supports() only matches a User whose role is actually staff — a sanity check on the subject-shape guard. */
    public function test_does_not_support_a_non_staff_user_subject(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $memberAccount = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($owner), $memberAccount, [StaffManagementVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}

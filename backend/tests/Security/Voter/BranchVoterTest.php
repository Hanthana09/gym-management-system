<?php

namespace App\Tests\Security\Voter;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\BranchVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * BranchVoter is copied verbatim from architecture doc §9.1 — no
 * single-gym collapse needed (Branch::getGym()/Gym::getOwner() are real
 * relations), so this proves the literal doc body behaves as documented.
 */
final class BranchVoterTest extends TestCase
{
    private BranchVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new BranchVoter();
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

    public function test_owner_can_manage_their_own_gyms_branch(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Gym', 'Address', $owner);
        $branch = new Branch($gym, 'Downtown', '1 Main St');

        $result = $this->voter->vote($this->tokenFor($owner), $branch, [BranchVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_owner_cannot_manage_someone_elses_branch_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $someoneElsesGym = new Gym('Other Gym', 'Address', $this->user(UserRole::OWNER));
        $branch = new Branch($someoneElsesGym, 'Downtown', '1 Main St');

        $result = $this->voter->vote($this->tokenFor($owner), $branch, [BranchVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_manage_a_branch_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $gym = new Gym('Gym', 'Address', $this->user(UserRole::OWNER));
        $branch = new Branch($gym, 'Downtown', '1 Main St');

        $result = $this->voter->vote($this->tokenFor($coach), $branch, [BranchVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_staff_cannot_manage_a_branch_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $gym = new Gym('Gym', 'Address', $this->user(UserRole::OWNER));
        $branch = new Branch($gym, 'Downtown', '1 Main St');

        $result = $this->voter->vote($this->tokenFor($staff), $branch, [BranchVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_cannot_manage_a_branch_403(): void
    {
        $member = $this->user(UserRole::MEMBER);
        $gym = new Gym('Gym', 'Address', $this->user(UserRole::OWNER));
        $branch = new Branch($gym, 'Downtown', '1 Main St');

        $result = $this->voter->vote($this->tokenFor($member), $branch, [BranchVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

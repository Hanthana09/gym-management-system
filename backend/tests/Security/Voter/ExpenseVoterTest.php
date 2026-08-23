<?php

namespace App\Tests\Security\Voter;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\ExpenseVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * ExpenseVoter is copied from architecture doc §9.1, adapted for this
 * codebase's single-gym isOwner()-alone collapse (see the Voter's own
 * docblock). Named after functional requirements §15.1's Given/When/Then
 * criteria where practical.
 */
final class ExpenseVoterTest extends TestCase
{
    private ExpenseVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new ExpenseVoter();
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

    private function gymWithBranch(): array
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);

        return [$gym, $branch, $owner];
    }

    private function expenseFor(Branch $branch, ?User $recordedBy = null): Expense
    {
        $recordedBy ??= $this->user(UserRole::OWNER);
        $category = new ExpenseCategory($branch->getGym(), 'Utilities');

        return new Expense($branch, $category, '150.00', 'LKR', 'Electricity bill', new \DateTimeImmutable('today'), $recordedBy);
    }

    // ---- Owner ---------------------------------------------------------

    public function test_owner_can_create_view_and_manage_an_expense_on_any_branch(): void
    {
        [, $branch, $owner] = $this->gymWithBranch();
        $expense = $this->expenseFor($branch, $owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $expense, [ExpenseVoter::CREATE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $expense, [ExpenseVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $expense, [ExpenseVoter::MANAGE]));
    }

    // ---- Staff: create + view on own assigned branch -------------------

    public function test_given_staff_is_assigned_to_a_branch_when_they_record_an_expense_there_then_it_succeeds(): void
    {
        [, $branch] = $this->gymWithBranch();
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);
        $expense = $this->expenseFor($branch);

        $result = $this->voter->vote($this->tokenFor($staff), $expense, [ExpenseVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_staff_assigned_to_a_branch_can_view_an_expense_there(): void
    {
        [, $branch] = $this->gymWithBranch();
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);
        $expense = $this->expenseFor($branch);

        $result = $this->voter->vote($this->tokenFor($staff), $expense, [ExpenseVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** functional requirements §15.1: "I am Staff... I can only do so for a branch I'm assigned to." */
    public function test_given_staff_is_not_assigned_to_a_branch_when_they_try_to_record_an_expense_there_then_403(): void
    {
        [, $branch] = $this->gymWithBranch();
        $staff = $this->user(UserRole::STAFF); // no BranchAssignment created
        $expense = $this->expenseFor($branch);

        $result = $this->voter->vote($this->tokenFor($staff), $expense, [ExpenseVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** roadmap Phase 16 multi-branch scoping: Staff assigned to Branch A cannot touch Branch B's expenses. */
    public function test_staff_assigned_to_one_branch_cannot_view_a_different_branchs_expense_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branchA = new Branch($gym, 'Branch A', '1 Main St', isPrimary: true);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branchA);
        $expenseAtB = $this->expenseFor($branchB);

        $result = $this->voter->vote($this->tokenFor($staff), $expenseAtB, [ExpenseVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * gym-management-dashboard-redesign.md Phase 1 stop condition: "a
     * coach or staff user can be assigned to 2+ branches... and existing
     * branch-scoped authorization correctly allows/denies based on the
     * full set." AppVoter::hasAssignedBranch() already iterates the
     * whole BranchAssignment collection (not a single value), but no
     * test previously exercised a user with more than one assignment —
     * every prior case here used exactly one.
     */
    public function test_staff_assigned_to_two_branches_can_act_on_both_but_not_a_third(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branchA = new Branch($gym, 'Branch A', '1 Main St', isPrimary: true);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $branchC = new Branch($gym, 'Branch C', '3 Third St');
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branchA);
        new BranchAssignment($staff, $branchB);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($staff), $this->expenseFor($branchA), [ExpenseVoter::CREATE]),
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($staff), $this->expenseFor($branchB), [ExpenseVoter::VIEW]),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($staff), $this->expenseFor($branchC), [ExpenseVoter::VIEW]),
        );
    }

    /** functional requirements §15.1: "Staff... try to edit or delete an existing expense (including one I created)... permission error." */
    public function test_given_staff_tries_to_edit_or_delete_an_expense_they_created_then_403(): void
    {
        [, $branch] = $this->gymWithBranch();
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);
        $expense = $this->expenseFor($branch, $staff);

        $result = $this->voter->vote($this->tokenFor($staff), $expense, [ExpenseVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- Coach/Member: denied entirely ----------------------------------

    /** functional requirements §15.1: "I am a Coach or Member... via any route... permission error — this role has no access to this feature at all." */
    public function test_given_a_coach_when_they_attempt_any_expense_action_then_403(): void
    {
        [, $branch] = $this->gymWithBranch();
        $coach = $this->user(UserRole::COACH);
        $expense = $this->expenseFor($branch);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $expense, [ExpenseVoter::CREATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $expense, [ExpenseVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $expense, [ExpenseVoter::MANAGE]));
    }

    public function test_given_a_member_when_they_attempt_any_expense_action_then_403(): void
    {
        [, $branch] = $this->gymWithBranch();
        $member = $this->user(UserRole::MEMBER);
        $expense = $this->expenseFor($branch);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $expense, [ExpenseVoter::CREATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $expense, [ExpenseVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $expense, [ExpenseVoter::MANAGE]));
    }
}

<?php

namespace App\Tests\Security\Voter;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\Gym;
use App\Entity\Invoice;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\InvoicePaymentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * gym-management-billing-v1.md §5.3 (RESET_BILLING_CYCLE, copied
 * verbatim) + §6/§8's required negative cases (Staff branch scoping on
 * RECORD_PAYMENT, resetBillingCycle Owner-only).
 */
final class InvoicePaymentVoterTest extends TestCase
{
    private InvoicePaymentVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new InvoicePaymentVoter();
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

    private function invoiceAt(Branch $branch): Invoice
    {
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $member = new MemberProfile($this->user(UserRole::MEMBER));
        $membership = new Membership($member, $plan, new \DateTimeImmutable('today'), new \DateTimeImmutable('+30 days'));

        return new Invoice($membership, '49.99');
    }

    // ---- RECORD_PAYMENT --------------------------------------------------

    public function test_owner_can_record_payment_on_any_branchs_invoice(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $invoice = $this->invoiceAt($branch);

        $result = $this->voter->vote($this->tokenFor($owner), $invoice, [InvoicePaymentVoter::RECORD_PAYMENT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_staff_assigned_to_the_invoices_branch_can_record_payment(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $invoice = $this->invoiceAt($branch);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);

        $result = $this->voter->vote($this->tokenFor($staff), $invoice, [InvoicePaymentVoter::RECORD_PAYMENT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** Required negative case: "Staff acting outside their assigned branch on payment... → 403." */
    public function test_staff_not_assigned_to_the_invoices_branch_cannot_record_payment_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branchA = new Branch($gym, 'Branch A', '1 Main St', isPrimary: true);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $invoice = $this->invoiceAt($branchB);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branchA);

        $result = $this->voter->vote($this->tokenFor($staff), $invoice, [InvoicePaymentVoter::RECORD_PAYMENT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_and_member_cannot_record_payment_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $invoice = $this->invoiceAt($branch);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($this->user(UserRole::COACH)), $invoice, [InvoicePaymentVoter::RECORD_PAYMENT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($this->user(UserRole::MEMBER)), $invoice, [InvoicePaymentVoter::RECORD_PAYMENT]));
    }

    // ---- RESET_BILLING_CYCLE (§5.3: Owner-only, no exceptions) ------------

    public function test_owner_can_reset_billing_cycle(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $invoice = $this->invoiceAt($branch);

        $result = $this->voter->vote($this->tokenFor($owner), $invoice, [InvoicePaymentVoter::RESET_BILLING_CYCLE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** Required negative case: "Staff submits resetBillingCycle: true → 403" — even Staff assigned to the right branch. */
    public function test_staff_cannot_reset_billing_cycle_even_on_their_own_branch_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $invoice = $this->invoiceAt($branch);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);

        $result = $this->voter->vote($this->tokenFor($staff), $invoice, [InvoicePaymentVoter::RESET_BILLING_CYCLE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

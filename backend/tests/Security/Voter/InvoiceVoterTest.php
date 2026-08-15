<?php

namespace App\Tests\Security\Voter;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\Invoice;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\InvoiceVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * InvoiceVoter is copied from architecture doc §9.1, adapted only to drop
 * the non-existent User::getGym() call (see the Voter's own docblock).
 * The MARK_PAID Member-403 case (test_a_member_can_never_mark_their_own_invoice_paid_403)
 * is the one roadmap Phase 10 calls out explicitly: "a Member confirming
 * their own payment defeats the entire point of this design."
 */
final class InvoiceVoterTest extends TestCase
{
    private InvoiceVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new InvoiceVoter();
    }

    private function user(UserRole $role): User
    {
        ++self::$counter;

        return new User("User " . self::$counter, "user" . self::$counter . '@example.com', '+1555000' . self::$counter, $role, UserStatus::ACTIVE);
    }

    private function invoiceFor(User $memberUser, ?User $owner = null): Invoice
    {
        $owner ??= $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $member = new MemberProfile($memberUser);
        $membership = new Membership($member, $plan, new \DateTimeImmutable('today'), new \DateTimeImmutable('+30 days'));

        return new Invoice($membership, '49.99');
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    // ---- VIEW ---------------------------------------------------------

    public function test_owner_can_view_any_invoice(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $invoice = $this->invoiceFor($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($owner), $invoice, [InvoiceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_member_can_view_their_own_invoice(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $invoice = $this->invoiceFor($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $invoice, [InvoiceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_view_someone_elses_invoice_403(): void
    {
        $invoice = $this->invoiceFor($this->user(UserRole::MEMBER));
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $invoice, [InvoiceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_view_an_invoice_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $invoice = $this->invoiceFor($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($coach), $invoice, [InvoiceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- MARK_PAID ------------------------------------------------------

    public function test_owner_can_mark_an_invoice_paid(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $invoice = $this->invoiceFor($this->user(UserRole::MEMBER), $owner);

        $result = $this->voter->vote($this->tokenFor($owner), $invoice, [InvoiceVoter::MARK_PAID]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * roadmap Phase 10: "MARK_PAID (Owner only, no exceptions, ever — a
     * Member confirming their own payment defeats the entire point of
     * this design)." This is the single most important case in this file.
     */
    public function test_a_member_can_never_mark_their_own_invoice_paid_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $invoice = $this->invoiceFor($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $invoice, [InvoiceVoter::MARK_PAID]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_coach_cannot_mark_an_invoice_paid_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $invoice = $this->invoiceFor($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($coach), $invoice, [InvoiceVoter::MARK_PAID]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

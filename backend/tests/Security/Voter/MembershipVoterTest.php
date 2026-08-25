<?php

namespace App\Tests\Security\Voter;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\PtSession;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\MembershipVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * MembershipVoter isn't written out in architecture doc §9.1 — this test
 * exercises the same structural pattern as MemberVoter (§9.1): MANAGE is
 * Owner-only and gym-scoped, other attributes are per-role ownership
 * checks against the subject.
 */
final class MembershipVoterTest extends TestCase
{
    private MembershipVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new MembershipVoter();
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

    private function plan(Gym $gym): MembershipPlan
    {
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);

        return new MembershipPlan($branch, 'Standard', '49.99', 30, ['Gym floor access']);
    }

    private function membership(MemberProfile $member, MembershipPlan $plan): Membership
    {
        return new Membership($member, $plan, new \DateTimeImmutable('today'), new \DateTimeImmutable('+30 days'));
    }

    // ---- MANAGE (Owner) --------------------------------------------------

    public function test_owner_can_manage_their_own_gyms_plan(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);

        $result = $this->voter->vote($this->tokenFor($owner), $plan, [MembershipVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_owner_can_manage_a_membership_in_their_own_gym(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);
        $memberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($memberUser);
        $membership = $this->membership($member, $plan);

        $result = $this->voter->vote($this->tokenFor($owner), $membership, [MembershipVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_different_owner_cannot_manage_someone_elses_gym_plan_403(): void
    {
        $ownerA = $this->user(UserRole::OWNER);
        $ownerB = $this->user(UserRole::OWNER);
        $gymA = new Gym("A's Gym", '1 A St', $ownerA);
        $plan = $this->plan($gymA);

        $result = $this->voter->vote($this->tokenFor($ownerB), $plan, [MembershipVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_non_owner_cannot_manage_a_plan_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $coach = $this->user(UserRole::COACH);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);

        $result = $this->voter->vote($this->tokenFor($coach), $plan, [MembershipVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- VIEW (Member, own membership only) -------------------------------

    public function test_member_can_view_their_own_membership(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);
        $memberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($memberUser);
        $membership = $this->membership($member, $plan);

        $result = $this->voter->vote($this->tokenFor($memberUser), $membership, [MembershipVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * functional requirements-style guarantee: a member must never see
     * another member's membership, even via the same attribute.
     */
    public function test_a_different_member_cannot_view_someone_elses_membership_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);
        $actualMemberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($actualMemberUser);
        $membership = $this->membership($member, $plan);

        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $membership, [MembershipVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_owner_cannot_use_view_attribute_on_a_membership_403(): void
    {
        // Owner has full access via MANAGE, not VIEW — VIEW belongs to the Member alone.
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);
        $memberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($memberUser);
        $membership = $this->membership($member, $plan);

        $result = $this->voter->vote($this->tokenFor($owner), $membership, [MembershipVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- RESPOND (Member, own membership only — pause/resume/cancel) ------

    public function test_member_can_respond_on_their_own_membership(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);
        $memberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($memberUser);
        $membership = $this->membership($member, $plan);

        $result = $this->voter->vote($this->tokenFor($memberUser), $membership, [MembershipVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * functional requirements §3.3-style guarantee: a member must not be
     * able to pause/cancel someone else's membership, even knowing its id.
     */
    public function test_a_different_member_cannot_respond_to_someone_elses_membership_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $plan = $this->plan($gym);
        $actualMemberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($actualMemberUser);
        $membership = $this->membership($member, $plan);

        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $membership, [MembershipVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- BILLING_MANAGE (suspend/reactivate) / BILLING_VIEW (billing-status read) — gym-management-billing-v1.md §6 ----

    public function test_owner_can_billing_manage_and_billing_view_any_membership(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $member = new MemberProfile($this->user(UserRole::MEMBER));
        $membership = $this->membership($member, $plan);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $membership, [MembershipVoter::BILLING_MANAGE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $membership, [MembershipVoter::BILLING_VIEW]));
    }

    public function test_staff_assigned_to_the_memberships_branch_can_billing_manage_and_view(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $member = new MemberProfile($this->user(UserRole::MEMBER));
        $membership = $this->membership($member, $plan);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($staff), $membership, [MembershipVoter::BILLING_MANAGE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($staff), $membership, [MembershipVoter::BILLING_VIEW]));
    }

    /** Required negative case: "Staff acting outside their assigned branch on... suspend/reactivate → 403." */
    public function test_staff_not_assigned_to_the_memberships_branch_cannot_billing_manage_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branchA = new Branch($gym, 'Branch A', '1 Main St', isPrimary: true);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $plan = new MembershipPlan($branchB, 'Standard', '49.99', 30, []);
        $member = new MemberProfile($this->user(UserRole::MEMBER));
        $membership = $this->membership($member, $plan);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branchA);

        $result = $this->voter->vote($this->tokenFor($staff), $membership, [MembershipVoter::BILLING_MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** §6: Coach gets read-only billing status for their own clients, nothing wider. */
    public function test_coach_can_billing_view_their_own_client_but_not_billing_manage(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $memberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($memberUser);
        $membership = $this->membership($member, $plan);
        $coachUser = $this->user(UserRole::COACH);
        $coachProfile = new CoachProfile($coachUser);
        new PtSession($coachProfile, $member, $branch, new \DateTimeImmutable('+1 day'), 60);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($coachUser), $membership, [MembershipVoter::BILLING_VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coachUser), $membership, [MembershipVoter::BILLING_MANAGE]));
    }

    public function test_coach_cannot_billing_view_a_member_who_is_not_their_client_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $member = new MemberProfile($this->user(UserRole::MEMBER));
        $membership = $this->membership($member, $plan);
        $coach = $this->user(UserRole::COACH); // never had a PtSession with this member

        $result = $this->voter->vote($this->tokenFor($coach), $membership, [MembershipVoter::BILLING_VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_can_billing_view_their_own_membership_but_not_billing_manage(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $memberUser = $this->user(UserRole::MEMBER);
        $member = new MemberProfile($memberUser);
        $membership = $this->membership($member, $plan);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($memberUser), $membership, [MembershipVoter::BILLING_VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($memberUser), $membership, [MembershipVoter::BILLING_MANAGE]));
    }
}

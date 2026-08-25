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
use App\Security\Voter\MemberVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * MemberVoter is copied from architecture doc §9.1, adapted only to drop
 * the non-existent User::getGym() call (see the Voter's own docblock) —
 * this test proves the adapted copy behaves as documented. Staff's VIEW
 * cases (roadmap Phase 16) construct a real branch/membership/assignment
 * graph entirely in-memory — MemberProfile::addMembership()/
 * User::addBranchAssignment() are called from those entities' own
 * constructors, so no EntityManager is needed here.
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

    private function branch(): Branch
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        return new Branch($gym, 'Main', '1 Main St', isPrimary: true);
    }

    /** Enrolls $profile in an active membership at $branch — this is what MemberVoter's Staff branch calls the member's "enrolling branch." */
    private function enrollAt(MemberProfile $profile, Branch $branch): void
    {
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        new Membership($profile, $plan, new \DateTimeImmutable('today'), new \DateTimeImmutable('+30 days'));
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

    /** MemberProfile::hasCoach() defines "own client" as having had a PT session with this coach — none exists here yet. */
    public function test_coach_cannot_view_a_member_before_any_pt_session_exists_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($coach), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * MemberProfile::hasCoach(): a real PT session, constructed entirely
     * in-memory (PtSession's constructor keeps MemberProfile's inverse
     * $ptSessions collection in sync, no EntityManager needed), is what
     * makes this member this coach's "own client."
     */
    public function test_coach_can_view_a_member_they_have_had_a_pt_session_with(): void
    {
        $coachUser = $this->user(UserRole::COACH);
        $coachProfile = new CoachProfile($coachUser);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        new PtSession($coachProfile, $profile, $this->branch(), new \DateTimeImmutable('+1 day'), 60);

        $result = $this->voter->vote($this->tokenFor($coachUser), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** roadmap Phase 16: Staff's VIEW now requires the member's enrolling branch to match one of Staff's own assignments — a real check, not the Phase 15 gym-wide collapse. */
    public function test_staff_can_view_a_member_enrolled_at_their_assigned_branch(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $branch = $this->branch();
        new BranchAssignment($staff, $branch);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $this->enrollAt($profile, $branch);

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** The other half of the same check: Staff assigned somewhere, but the member enrolled at a different branch. */
    public function test_staff_cannot_view_a_member_enrolled_at_a_branch_they_arent_assigned_to_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $this->branch()); // assigned elsewhere
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $this->enrollAt($profile, $this->branch()); // a different branch

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** functional requirements §11.2-adjacent: a member with no membership at all has no "enrolling branch" to match against — Staff sees nothing, not everything. */
    public function test_staff_cannot_view_a_member_with_no_membership_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $this->branch());
        $profile = new MemberProfile($this->user(UserRole::MEMBER)); // never enrolled

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- gym scoping (gym-management-member-profile-extension.md §6.1/§9.1) ----

    public function test_owner_can_view_a_member_of_their_own_gym(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $profile->assignGym($gym);

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_owner_cannot_view_a_member_of_a_different_owners_gym_403(): void
    {
        $ownerA = $this->user(UserRole::OWNER);
        $ownerB = $this->user(UserRole::OWNER);
        $gymB = new Gym('Gym B', '2 Side St', $ownerB);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $profile->assignGym($gymB);

        $result = $this->voter->vote($this->tokenFor($ownerA), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** Pre-backfill window: a row with no gym assigned yet stays visible to any Owner, same as the pre-this-phase collapse. */
    public function test_owner_can_view_a_member_with_no_gym_assigned_yet(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [MemberVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_owner_cannot_manage_a_member_of_a_different_owners_gym_403(): void
    {
        $ownerA = $this->user(UserRole::OWNER);
        $ownerB = $this->user(UserRole::OWNER);
        $gymB = new Gym('Gym B', '2 Side St', $ownerB);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $profile->assignGym($gymB);

        $result = $this->voter->vote($this->tokenFor($ownerA), $profile, [MemberVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
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

    /** functional requirements §11.2: Staff has "no edit/suspend/remove actions available." Unchanged by the EDIT_PROFILE follow-up feature — MANAGE (suspend/reactivate) deliberately stays Owner-only. */
    public function test_staff_cannot_manage_a_member_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- EDIT_PROFILE (follow-up feature: editable/manual Member ID mode) ----

    public function test_owner_can_edit_profile_of_a_member_in_their_own_gym(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $profile->assignGym($gym);

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [MemberVoter::EDIT_PROFILE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_owner_cannot_edit_profile_of_a_member_in_a_different_owners_gym_403(): void
    {
        $ownerA = $this->user(UserRole::OWNER);
        $ownerB = $this->user(UserRole::OWNER);
        $gymB = new Gym('Gym B', '2 Side St', $ownerB);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));
        $profile->assignGym($gymB);

        $result = $this->voter->vote($this->tokenFor($ownerA), $profile, [MemberVoter::EDIT_PROFILE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** Deliberately gym-wide/unscoped — no branch context exists for a freshly walk-in-created member (see this Voter's docblock). */
    public function test_staff_can_edit_profile_of_any_member_unscoped(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [MemberVoter::EDIT_PROFILE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_coach_cannot_edit_profile_of_a_member_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $profile = new MemberProfile($this->user(UserRole::MEMBER));

        $result = $this->voter->vote($this->tokenFor($coach), $profile, [MemberVoter::EDIT_PROFILE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_cannot_edit_their_own_profile_via_edit_profile_403(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $profile, [MemberVoter::EDIT_PROFILE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

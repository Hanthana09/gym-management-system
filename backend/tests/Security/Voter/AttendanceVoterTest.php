<?php

namespace App\Tests\Security\Voter;

use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\AttendanceVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * AttendanceVoter is copied from architecture doc §9.1, updated for
 * roadmap Phase 16 — VIEW's subject changed from MemberProfile to
 * AttendanceLog (§9.1's updated body), so every VIEW case here now
 * constructs a real log with a real branch, entirely in-memory (no
 * EntityManager — Branch/BranchAssignment's constructors keep
 * User::branchAssignments in sync, same pattern as BranchVoterTest).
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

    private function branch(): Branch
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);

        return new Branch($gym, 'Main', '1 Main St', isPrimary: true);
    }

    private function logFor(MemberProfile $member, ?Branch $branch = null): AttendanceLog
    {
        return new AttendanceLog($member, $branch ?? $this->branch(), new \DateTimeImmutable(), CheckInMethod::MANUAL);
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

    /**
     * roadmap Phase 15.1/16: Staff gets the same front-desk check-in
     * capability as Owner, and CHECK_IN stays hub-permissive on WHICH
     * member regardless of branch assignment — this Staff user has no
     * branch assignment at all here, and still passes, proving the Voter
     * itself makes no branch check for CHECK_IN (that's a controller-level
     * concern, per AttendanceController's own docblock).
     */
    public function test_staff_can_check_in_on_behalf_of_a_member_front_desk(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($staff), $profile, [AttendanceVoter::CHECK_IN]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ---- CHECK_OUT (check-in-timer feature: self only, no front-desk variant) ----

    public function test_member_can_check_out_for_themselves(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($memberUser), $profile, [AttendanceVoter::CHECK_OUT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_check_out_for_someone_else_403(): void
    {
        $actualMemberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($actualMemberUser);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $profile, [AttendanceVoter::CHECK_OUT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** Unlike CHECK_IN, CHECK_OUT has no front-desk variant — Owner gets no special-case grant here. */
    public function test_owner_cannot_check_out_on_behalf_of_a_member_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);

        $result = $this->voter->vote($this->tokenFor($owner), $profile, [AttendanceVoter::CHECK_OUT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
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

    // ---- VIEW (self, Coach's own clients, or Staff's assigned branch) -----

    public function test_member_can_view_their_own_attendance(): void
    {
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);
        $log = $this->logFor($profile);

        $result = $this->voter->vote($this->tokenFor($memberUser), $log, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_view_someone_elses_attendance_403(): void
    {
        $actualMemberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($actualMemberUser);
        $log = $this->logFor($profile);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $log, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * MemberProfile::hasCoach() defines "own client" as having had at
     * least one PT session with this coach (any status) — with no such
     * session, a Coach has no relationship to this member at all yet.
     */
    public function test_coach_cannot_view_a_members_attendance_before_any_pt_session_exists_403(): void
    {
        $coach = $this->user(UserRole::COACH);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);
        $log = $this->logFor($profile);

        $result = $this->voter->vote($this->tokenFor($coach), $log, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * MemberProfile::hasCoach(): a real PT session (constructed entirely
     * in-memory — PtSession's own constructor keeps MemberProfile's
     * inverse $ptSessions collection in sync, no EntityManager needed
     * here, same pattern as Membership/$memberships elsewhere in this
     * test) is what makes this member this coach's "own client."
     */
    public function test_coach_can_view_a_members_attendance_once_they_have_had_a_pt_session_together(): void
    {
        $coachUser = $this->user(UserRole::COACH);
        $coachProfile = new CoachProfile($coachUser);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);
        $branch = $this->branch();
        new PtSession($coachProfile, $profile, $branch, new \DateTimeImmutable('+1 day'), 60);
        $log = $this->logFor($profile, $branch);

        $result = $this->voter->vote($this->tokenFor($coachUser), $log, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** roadmap Phase 16: Staff's VIEW now requires an actual branch assignment matching the log's branch — a real check, not the Phase 15 gym-wide collapse. */
    public function test_staff_can_view_a_members_attendance_at_their_assigned_branch(): void
    {
        $staff = $this->user(UserRole::STAFF);
        $branch = $this->branch();
        new BranchAssignment($staff, $branch);
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);
        $log = $this->logFor($profile, $branch);

        $result = $this->voter->vote($this->tokenFor($staff), $log, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** The other half of the same check: Staff assigned somewhere, but not to THIS log's branch. */
    public function test_staff_cannot_view_attendance_at_a_branch_they_arent_assigned_to_403(): void
    {
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $this->branch()); // assigned elsewhere
        $memberUser = $this->user(UserRole::MEMBER);
        $profile = new MemberProfile($memberUser);
        $log = $this->logFor($profile, $this->branch()); // a different branch

        $result = $this->voter->vote($this->tokenFor($staff), $log, [AttendanceVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * gym-management-dashboard-redesign.md Phase 1 stop condition: Staff
     * assigned to 2+ branches must be allowed at every one of them, and
     * still denied at a third — no prior test in this file assigned more
     * than one branch to the same Staff user.
     */
    public function test_staff_assigned_to_two_branches_can_view_attendance_at_both_but_not_a_third(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $branchA = new Branch($gym, 'Branch A', '1 Main St', isPrimary: true);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $branchC = new Branch($gym, 'Branch C', '3 Third St');
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branchA);
        new BranchAssignment($staff, $branchB);

        $memberAtA = new MemberProfile($this->user(UserRole::MEMBER));
        $memberAtB = new MemberProfile($this->user(UserRole::MEMBER));
        $memberAtC = new MemberProfile($this->user(UserRole::MEMBER));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($staff), $this->logFor($memberAtA, $branchA), [AttendanceVoter::VIEW]),
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($staff), $this->logFor($memberAtB, $branchB), [AttendanceVoter::VIEW]),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($staff), $this->logFor($memberAtC, $branchC), [AttendanceVoter::VIEW]),
        );
    }
}

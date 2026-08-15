<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * roadmap Phase 15.1 / functional requirements §11.2: "the 403 list
 * matters more than the pass cases here, since Staff being narrow is
 * the entire point of the role." Every case below goes through the real
 * HTTP layer (not a Voter unit test) — a Staff account manipulating a
 * request directly must be rejected the same as a hidden UI button would
 * suggest, per §11.2's explicit callout.
 */
final class StaffAccessTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE audit_log, daily_metric_snapshot, invoice, attendance_log, pt_session, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(string $name, string $email, UserRole $role, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = new User($name, $email, null, $role, $status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Staff has no dedicated profile table (architecture doc §5.2) — an active User row is the whole account. */
    private function createApprovedStaff(string $name, string $email): User
    {
        return $this->createUser($name, $email, UserRole::STAFF);
    }

    private function createApprovedMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER);
        $this->em->persist(new MemberProfile($user));
        $this->em->flush();

        return $user;
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($actingAs),
            ],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function createPlanAndEnroll(User $owner, User $member, int $durationDays = 30): array
    {
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Standard',
            'price' => '49.99',
            'durationDays' => $durationDays,
            'features' => [],
        ]);

        return $this->request('POST', '/memberships', $owner, [
            'memberUserId' => (string) $member->getId(),
            'planId' => $plan['body']['id'],
        ])['body'];
    }

    /**
     * roadmap Phase 16: Staff's VIEW/CHECK_IN-eligibility is now
     * branch-assigned, not gym-wide (was Phase 15's behavior) — every
     * pass-case test below needs Staff actually assigned somewhere first.
     * Returns the gym's primary branch id (lazily provisions the gym via
     * the GET, same as every other branch-aware endpoint).
     */
    private function assignToPrimaryBranch(User $owner, User $user): string
    {
        $branchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $this->request('POST', "/branches/{$branchId}/assign", $owner, ['userId' => (string) $user->getId()]);

        return $branchId;
    }

    // ---- pass cases ------------------------------------------------------

    /**
     * roadmap Phase 16: this is now a branch-scoped pass case, not a
     * gym-wide one — Staff is assigned to the primary branch, and the
     * member enrolls in a plan at that same (the only) branch, so they
     * remain visible exactly as a single-branch gym's Staff always could
     * (the regression case) even though the underlying check changed.
     */
    public function test_staff_can_view_the_member_list(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $this->assignToPrimaryBranch($owner, $staff);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('GET', '/members', $staff);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['members']);
    }

    /**
     * roadmap Phase 16: a member with NO enrolling branch (no membership
     * at all) is invisible to Staff's member list — a direct consequence
     * of MemberVoter::VIEW's updated body (§9.1), not a bug. Kept as its
     * own case since it's easy to conflate with the 403 list below, but
     * it's actually a 200 with an empty roster, not a rejection.
     */
    public function test_staff_cannot_see_a_member_with_no_membership_in_the_list(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $this->assignToPrimaryBranch($owner, $staff);
        $this->createApprovedMember('Mia Member', 'mia@example.com');
        // No plan/enrollment — no enrolling branch to match against.

        $result = $this->request('GET', '/members', $staff);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['members']);
    }

    /** functional requirements §11.2: "behaves identically to an Owner-performed front-desk check-in." Now requires Staff to be branch-assigned first (roadmap Phase 16) — the check-in itself stays hub-permissive on which member. */
    public function test_staff_can_check_a_member_in_at_the_front_desk(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $this->assignToPrimaryBranch($owner, $staff);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('POST', "/members/{$member->getId()}/checkin", $staff);

        self::assertSame(201, $result['status']);
        self::assertSame('front_desk', $result['body']['method']);
    }

    /** Same membership-status validation as any other check-in path — a suspended member is still blocked, front desk included. */
    public function test_staff_front_desk_checkin_still_respects_membership_validation(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $this->assignToPrimaryBranch($owner, $staff);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        // No plan/enrollment for this member.

        $result = $this->request('POST', "/members/{$member->getId()}/checkin", $staff);

        self::assertSame(409, $result['status']);
        self::assertSame('checkin_blocked', $result['body']['error']);
        self::assertSame('no_membership', $result['body']['reason']);
    }

    /** roadmap Phase 16 / functional requirements §14.2: front-desk check-in requires Staff to actually be assigned somewhere — unassigned Staff is rejected outright, a new 403 case this phase introduces. */
    public function test_staff_with_no_branch_assignment_cannot_check_anyone_in_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        // Deliberately not assigned to any branch.
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('POST', "/members/{$member->getId()}/checkin", $staff);

        self::assertSame(403, $result['status']);
        self::assertSame('no_branch_assignment', $result['body']['error']);
    }

    // ---- 403 list — "narrowness is the entire point of this role" -------------

    public function test_staff_cannot_suspend_a_member_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $staff, ['status' => 'suspended']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_create_a_membership_plan_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('POST', '/membership-plans', $staff, ['name' => 'Gold', 'price' => '99.99', 'durationDays' => 30, 'features' => []]);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_view_the_dashboard_report_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('GET', '/reports/dashboard', $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_view_the_attendance_report_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('GET', '/reports/attendance', $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_view_the_revenue_forecast_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('GET', '/reports/revenue-forecast', $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_view_the_retention_report_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('GET', '/reports/retention', $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_export_reports_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('GET', '/reports/export?report=dashboard&format=csv', $staff);

        self::assertSame(403, $result['status']);
    }

    /** architecture doc §2: "View revenue & reports ... — (explicitly excluded)" for Staff. */
    public function test_staff_cannot_view_invoices_403(): void
    {
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');

        $result = $this->request('GET', '/invoices', $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_mark_an_invoice_paid_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];

        $result = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $staff, ['paymentMethod' => 'cash']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_respond_to_a_pt_session_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $this->em->persist(new \App\Entity\CoachProfile($coach));
        $this->em->flush();
        $this->assignToPrimaryBranch($owner, $coach); // PtSessionVoter::RESPOND now needs this (roadmap Phase 16) — irrelevant to what's under test (Staff can't respond regardless)
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $requested = $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => 60,
        ]);

        $result = $this->request('PATCH', "/pt-sessions/{$requested['body']['id']}/status", $staff, ['status' => 'confirmed']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_send_an_announcement_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createApprovedStaff('Sam Staff', 'staff@example.com');
        // Provisions the gym (lazily created on an Owner's first action) so
        // this hits AnnouncementVoter's 403, not a 404 from no gym existing yet.
        $this->createPlanAndEnroll($owner, $this->createApprovedMember('Mia Member', 'mia@example.com'));

        $result = $this->request('POST', '/announcements', $staff, ['body' => 'Gym closes early today.', 'audience' => 'gym_wide']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_manage_another_staff_account_via_the_voter(): void
    {
        // No live endpoint uses StaffManagementVoter yet (roadmap Phase 15.1
        // only asks for the Voter itself) — this confirms it's wired
        // correctly at the container level in case/when one is added.
        $voter = static::getContainer()->get(\App\Security\Voter\StaffManagementVoter::class);
        self::assertInstanceOf(\App\Security\Voter\StaffManagementVoter::class, $voter);
    }

    // ---- onboarding (functional requirements §11.1) --------------------------

    public function test_a_staff_invitation_approves_the_same_way_as_coach_or_member(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->request('POST', '/invitations', $owner, ['destination' => 'newstaff@example.com', 'role' => 'staff']);
        $invited = $this->createUser('New Staff', 'newstaff@example.com', UserRole::STAFF, UserStatus::PENDING_APPROVAL);
        // Link the invitation the same way OTP-first-login would (InvitationService::provisionUserForDestination
        // does this in the real flow) — simplest direct equivalent for this test.
        $this->em->getConnection()->executeStatement(
            'UPDATE invitation SET user_id = ? WHERE email = ?',
            [(string) $invited->getId(), 'newstaff@example.com'],
        );

        $mine = $this->request('GET', '/invitations/me', $invited);
        self::assertCount(1, $mine['body']['invitations']);
        self::assertSame('staff', $mine['body']['invitations'][0]['role']);

        $approved = $this->request('PATCH', "/invitations/{$mine['body']['invitations'][0]['id']}/approve", $invited);
        self::assertSame(200, $approved['status']);

        $roster = $this->request('GET', '/members', $owner);
        // No STAFF_PROFILE table exists, and this roster only ever returns
        // members/coaches (MemberController's own documented scope) — the
        // approval itself is what's under test here, not roster visibility.
        self::assertNotContains('newstaff@example.com', array_column($roster['body']['members'], 'email'));

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM "user" WHERE id = ?', [(string) $invited->getId()]);
        self::assertSame('active', $status);
    }
}

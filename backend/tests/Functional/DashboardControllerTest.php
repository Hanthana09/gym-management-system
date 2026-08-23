<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\PtSession;
use App\Entity\User;
use App\Enum\PtSessionStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers gym-management-dashboard-redesign.md Phase 3's four dashboard
 * endpoints and §6's full negative/permission list, including the new
 * multi-branch cases.
 */
final class DashboardControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE pt_session, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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

    private function gymAndBranch(User $owner): array
    {
        $gym = new Gym("Owner's Gym", '1 Main St', $owner);
        $this->em->persist($gym);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($branch);
        $this->em->flush();

        return [$gym, $branch];
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    private function get(string $uri, ?User $actingAs): array
    {
        $server = ['HTTPS' => 'on'];
        if ($actingAs !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->accessTokenFor($actingAs);
        }

        $this->client->request('GET', '/api/v1/dashboard' . $uri, server: $server);
        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    // ---- Owner -------------------------------------------------------------

    public function test_owner_can_view_their_dashboard(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->gymAndBranch($owner);

        $result = $this->get('/owner', $owner);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('todayCheckins', $result['body']);
        self::assertArrayHasKey('unreadNotificationCount', $result['body']);
    }

    public function test_staff_calling_owner_dashboard_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner2@example.com', UserRole::OWNER);
        $this->gymAndBranch($owner);
        $staff = $this->createUser('Sam Staff', 'staff2@example.com', UserRole::STAFF);

        self::assertSame(403, $this->get('/owner', $staff)['status']);
    }

    public function test_unauthenticated_request_to_owner_dashboard_401(): void
    {
        self::assertSame(401, $this->get('/owner', null)['status']);
    }

    // ---- Staff ---------------------------------------------------------------

    public function test_single_branch_staff_omitting_branch_param_defaults_to_their_one_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner3@example.com', UserRole::OWNER);
        [, $branch] = $this->gymAndBranch($owner);
        $staff = $this->createUser('Sam Staff', 'staff3@example.com', UserRole::STAFF);
        $this->em->persist(new BranchAssignment($staff, $branch));
        $this->em->flush();

        $result = $this->get('/staff', $staff);

        self::assertSame(200, $result['status']);
        self::assertSame((string) $branch->getId(), $result['body']['branchId']);
    }

    public function test_multi_branch_staff_omitting_branch_param_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner4@example.com', UserRole::OWNER);
        [$gym, $branchA] = $this->gymAndBranch($owner);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $this->em->persist($branchB);
        $staff = $this->createUser('Sam Staff', 'staff4@example.com', UserRole::STAFF);
        $this->em->persist(new BranchAssignment($staff, $branchA));
        $this->em->persist(new BranchAssignment($staff, $branchB));
        $this->em->flush();

        $result = $this->get('/staff', $staff);

        self::assertSame(400, $result['status']);
    }

    public function test_multi_branch_staff_can_pass_either_assigned_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner5@example.com', UserRole::OWNER);
        [$gym, $branchA] = $this->gymAndBranch($owner);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $this->em->persist($branchB);
        $staff = $this->createUser('Sam Staff', 'staff5@example.com', UserRole::STAFF);
        $this->em->persist(new BranchAssignment($staff, $branchA));
        $this->em->persist(new BranchAssignment($staff, $branchB));
        $this->em->flush();

        $resultA = $this->get('/staff?branch=' . $branchA->getId(), $staff);
        $resultB = $this->get('/staff?branch=' . $branchB->getId(), $staff);

        self::assertSame(200, $resultA['status']);
        self::assertSame((string) $branchA->getId(), $resultA['body']['branchId']);
        self::assertSame(200, $resultB['status']);
        self::assertSame((string) $branchB->getId(), $resultB['body']['branchId']);
    }

    public function test_staff_passing_a_branch_they_are_not_assigned_to_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner6@example.com', UserRole::OWNER);
        [$gym, $branchA] = $this->gymAndBranch($owner);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $this->em->persist($branchB);
        $staff = $this->createUser('Sam Staff', 'staff6@example.com', UserRole::STAFF);
        $this->em->persist(new BranchAssignment($staff, $branchA));
        $this->em->flush();

        $result = $this->get('/staff?branch=' . $branchB->getId(), $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_coach_calling_staff_dashboard_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner7@example.com', UserRole::OWNER);
        $this->gymAndBranch($owner);
        $coach = $this->createUser('Cara Coach', 'coach7@example.com', UserRole::COACH);

        self::assertSame(403, $this->get('/staff', $coach)['status']);
    }

    public function test_staff_dashboard_includes_expiring_memberships_for_their_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner8@example.com', UserRole::OWNER);
        [, $branch] = $this->gymAndBranch($owner);
        $staff = $this->createUser('Sam Staff', 'staff8@example.com', UserRole::STAFF);
        $this->em->persist(new BranchAssignment($staff, $branch));

        $memberUser = $this->createUser('Mia Member', 'member8@example.com', UserRole::MEMBER);
        $memberProfile = new MemberProfile($memberUser);
        $this->em->persist($memberProfile);
        $plan = new MembershipPlan($branch, 'Gold', '50.00', 30, []);
        $this->em->persist($plan);
        $membership = new Membership($memberProfile, $plan, new \DateTimeImmutable('-25 days'), new \DateTimeImmutable('+5 days'));
        $this->em->persist($membership);
        $this->em->flush();

        $result = $this->get('/staff', $staff);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['body']['expiringMembershipsCount']);
        self::assertSame('Mia Member', $result['body']['expiringMemberships'][0]['memberName']);
    }

    // ---- Coach ---------------------------------------------------------------

    public function test_single_branch_coach_omitting_branch_param_defaults_to_their_one_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner9@example.com', UserRole::OWNER);
        [, $branch] = $this->gymAndBranch($owner);
        $coach = $this->createUser('Cara Coach', 'coach9@example.com', UserRole::COACH);
        $this->em->persist(new CoachProfile($coach));
        $this->em->persist(new BranchAssignment($coach, $branch));
        $this->em->flush();

        $result = $this->get('/coach', $coach);

        self::assertSame(200, $result['status']);
        self::assertSame((string) $branch->getId(), $result['body']['branchId']);
    }

    public function test_multi_branch_coach_omitting_branch_param_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner10@example.com', UserRole::OWNER);
        [$gym, $branchA] = $this->gymAndBranch($owner);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $this->em->persist($branchB);
        $coach = $this->createUser('Cara Coach', 'coach10@example.com', UserRole::COACH);
        $this->em->persist(new CoachProfile($coach));
        $this->em->persist(new BranchAssignment($coach, $branchA));
        $this->em->persist(new BranchAssignment($coach, $branchB));
        $this->em->flush();

        self::assertSame(400, $this->get('/coach', $coach)['status']);
    }

    public function test_multi_branch_coach_passing_an_unassigned_branch_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner11@example.com', UserRole::OWNER);
        [$gym, $branchA] = $this->gymAndBranch($owner);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $this->em->persist($branchB);
        $coach = $this->createUser('Cara Coach', 'coach11@example.com', UserRole::COACH);
        $this->em->persist(new CoachProfile($coach));
        $this->em->persist(new BranchAssignment($coach, $branchA));
        $this->em->flush();

        self::assertSame(403, $this->get('/coach?branch=' . $branchB->getId(), $coach)['status']);
    }

    public function test_member_calling_coach_dashboard_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner12@example.com', UserRole::OWNER);
        $this->gymAndBranch($owner);
        $member = $this->createUser('Mia Member', 'member12@example.com', UserRole::MEMBER);

        self::assertSame(403, $this->get('/coach', $member)['status']);
    }

    /** §6: "Coach A cannot see Coach B's sessions via any parameter on /dashboard/coach." */
    public function test_a_coach_never_sees_another_coachs_sessions(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner13@example.com', UserRole::OWNER);
        [, $branch] = $this->gymAndBranch($owner);
        $coachA = $this->createUser('Cara Coach', 'coacha13@example.com', UserRole::COACH);
        $coachAProfile = new CoachProfile($coachA);
        $this->em->persist($coachAProfile);
        $this->em->persist(new BranchAssignment($coachA, $branch));
        $coachB = $this->createUser('Ben Coach', 'coachb13@example.com', UserRole::COACH);
        $coachBProfile = new CoachProfile($coachB);
        $this->em->persist($coachBProfile);
        $this->em->persist(new BranchAssignment($coachB, $branch));

        $memberUser = $this->createUser('Mia Member', 'member13@example.com', UserRole::MEMBER);
        $memberProfile = new MemberProfile($memberUser);
        $this->em->persist($memberProfile);
        $sessionForB = new PtSession($coachBProfile, $memberProfile, $branch, new \DateTimeImmutable('+1 hour'), 60);
        $this->em->persist($sessionForB);
        $this->em->flush();

        $result = $this->get('/coach', $coachA);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['todaySessions']);
    }

    public function test_coach_dashboard_lists_todays_sessions_at_their_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner14@example.com', UserRole::OWNER);
        [, $branch] = $this->gymAndBranch($owner);
        $coach = $this->createUser('Cara Coach', 'coach14@example.com', UserRole::COACH);
        $coachProfile = new CoachProfile($coach);
        $this->em->persist($coachProfile);
        $this->em->persist(new BranchAssignment($coach, $branch));
        $memberUser = $this->createUser('Mia Member', 'member14@example.com', UserRole::MEMBER);
        $memberProfile = new MemberProfile($memberUser);
        $this->em->persist($memberProfile);
        $session = new PtSession($coachProfile, $memberProfile, $branch, new \DateTimeImmutable('+2 hours'), 60);
        $this->em->persist($session);
        $this->em->flush();

        $result = $this->get('/coach', $coach);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['todaySessions']);
        self::assertSame('Mia Member', $result['body']['todaySessions'][0]['memberName']);
        self::assertSame(1, $result['body']['assignedMembersCount']);
    }

    // ---- Member ----------------------------------------------------------

    public function test_member_can_view_their_dashboard(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner15@example.com', UserRole::OWNER);
        $this->gymAndBranch($owner);
        $memberUser = $this->createUser('Mia Member', 'member15@example.com', UserRole::MEMBER);
        $this->em->persist(new MemberProfile($memberUser));
        $this->em->flush();

        $result = $this->get('/member', $memberUser);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('nextSession', $result['body']);
        self::assertArrayHasKey('recentAttendance', $result['body']);
    }

    public function test_coach_calling_member_dashboard_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner16@example.com', UserRole::OWNER);
        $this->gymAndBranch($owner);
        $coach = $this->createUser('Cara Coach', 'coach16@example.com', UserRole::COACH);

        self::assertSame(403, $this->get('/member', $coach)['status']);
    }

    public function test_unauthenticated_request_to_member_dashboard_401(): void
    {
        self::assertSame(401, $this->get('/member', null)['status']);
    }

    /**
     * §6: "Member requesting /dashboard/member sees sessions/attendance
     * across both their branches in one response... confirm this is NOT
     * accidentally branch-filtered by a leftover branch param."
     */
    public function test_member_dashboard_ignores_any_branch_query_param_and_stays_hub_wide(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner17@example.com', UserRole::OWNER);
        [$gym, $branchA] = $this->gymAndBranch($owner);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $this->em->persist($branchB);
        $memberUser = $this->createUser('Mia Member', 'member17@example.com', UserRole::MEMBER);
        $memberProfile = new MemberProfile($memberUser);
        $this->em->persist($memberProfile);
        $this->em->flush();
        $this->em->persist(new \App\Entity\AttendanceLog($memberProfile, $branchA, new \DateTimeImmutable('-2 hours'), \App\Enum\CheckInMethod::MANUAL));
        $this->em->persist(new \App\Entity\AttendanceLog($memberProfile, $branchB, new \DateTimeImmutable('-1 hour'), \App\Enum\CheckInMethod::MANUAL));
        $this->em->flush();

        // A stray branch param must be silently ignored — no branch-scoping logic exists on this endpoint at all.
        $result = $this->get('/member?branch=' . $branchA->getId(), $memberUser);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['recentAttendance']);
        $branchNames = array_column(array_column($result['body']['recentAttendance'], 'branch'), 'name');
        self::assertContains('Main', $branchNames);
        self::assertContains('Branch B', $branchNames);
    }
}

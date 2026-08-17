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
 * roadmap Phase 16.1: branch CRUD and Coach/Staff assignment, all through
 * the real HTTP layer per this codebase's established "every role-scoped
 * action needs a Voter test with a passing case and a 403 case" rule
 * (CLAUDE.md), now exercised via BranchController rather than a bare
 * Voter unit test for the endpoint-level behavior (404s, 409s, payload
 * shape) BranchVoterTest doesn't cover.
 */
final class BranchControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE attendance_log, membership, membership_plan, member_profile, branch_assignment, branch, gym, "user" CASCADE',
        );
    }

    private function createUser(string $name, UserRole $role): User
    {
        $user = new User($name, $name . '@example.com', null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $token = static::getContainer()->get(TokenIssuer::class)->createAccessToken($actingAs);
        $this->client->request(
            $method,
            '/api' . $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' || $method === 'DELETE' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    // ---- pass cases ------------------------------------------------------

    public function test_owner_can_create_a_second_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $this->request('GET', '/branches', $owner); // provisions the primary branch

        $result = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St']);

        self::assertSame(201, $result['status']);
        self::assertFalse($result['body']['isPrimary']);

        $list = $this->request('GET', '/branches', $owner);
        self::assertCount(2, $list['body']['branches']);
    }

    public function test_owner_can_assign_a_coach_to_a_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $result = $this->request('POST', "/branches/{$branch['id']}/assign", $owner, ['userId' => (string) $coach->getId()]);

        self::assertSame(201, $result['status']);
        self::assertCount(1, $result['body']['assignments']);
        self::assertSame('coach', $result['body']['assignments'][0]['role']);
    }

    public function test_owner_can_unassign_a_coach(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $this->request('POST', "/branches/{$branch['id']}/assign", $owner, ['userId' => (string) $coach->getId()]);

        $result = $this->request('DELETE', "/branches/{$branch['id']}/assign/{$coach->getId()}", $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['assignments']);
    }

    public function test_assigning_a_member_is_rejected_409(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $member = $this->createUser('Mia Member', UserRole::MEMBER);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $result = $this->request('POST', "/branches/{$branch['id']}/assign", $owner, ['userId' => (string) $member->getId()]);

        self::assertSame(409, $result['status']);
        self::assertSame('invalid_role', $result['body']['error']);
    }

    public function test_assigning_the_same_coach_twice_is_rejected_409(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $this->request('POST', "/branches/{$branch['id']}/assign", $owner, ['userId' => (string) $coach->getId()]);

        $result = $this->request('POST', "/branches/{$branch['id']}/assign", $owner, ['userId' => (string) $coach->getId()]);

        self::assertSame(409, $result['status']);
        self::assertSame('already_assigned', $result['body']['error']);
    }

    public function test_owner_can_deactivate_a_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $result = $this->request('PATCH', "/branches/{$branch['id']}", $owner, ['status' => 'inactive']);

        self::assertSame(200, $result['status']);
        self::assertSame('inactive', $result['body']['status']);
    }

    public function test_any_authenticated_role_can_list_branches(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $member = $this->createUser('Mia Member', UserRole::MEMBER);
        $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St']);

        $result = $this->request('GET', '/branches', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['branches']); // primary + Downtown
    }

    // ---- 403 cases ---------------------------------------------------------

    public function test_coach_cannot_create_a_branch_403(): void
    {
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);

        $result = $this->request('POST', '/branches', $coach, ['name' => 'Downtown', 'address' => '1 Main St']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_create_a_branch_403(): void
    {
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);

        $result = $this->request('POST', '/branches', $staff, ['name' => 'Downtown', 'address' => '1 Main St']);

        self::assertSame(403, $result['status']);
    }

    public function test_member_cannot_create_a_branch_403(): void
    {
        $member = $this->createUser('Mia Member', UserRole::MEMBER);

        $result = $this->request('POST', '/branches', $member, ['name' => 'Downtown', 'address' => '1 Main St']);

        self::assertSame(403, $result['status']);
    }

    public function test_non_owner_cannot_assign_a_coach_403(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $result = $this->request('POST', "/branches/{$branch['id']}/assign", $staff, ['userId' => (string) $coach->getId()]);

        self::assertSame(403, $result['status']);
    }

    public function test_non_owner_cannot_list_assignable_users_403(): void
    {
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);

        $result = $this->request('GET', '/branches/assignable-users', $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_owner_cannot_manage_another_owners_branch_403(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $otherOwner = $this->createUser('Oscar Owner', UserRole::OWNER);

        $result = $this->request('PATCH', "/branches/{$branch['id']}", $otherOwner, ['name' => 'Hijacked']);

        self::assertSame(403, $result['status']);
    }

    // ---- branch delete facility --------------------------------------------

    public function test_owner_can_delete_an_unused_non_primary_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $result = $this->request('DELETE', "/branches/{$branch['id']}", $owner);

        self::assertSame(204, $result['status']);
        $list = $this->request('GET', '/branches', $owner);
        self::assertCount(1, $list['body']['branches']); // just the primary now
    }

    public function test_deleting_a_branch_removes_its_coach_assignment_too(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $this->request('POST', "/branches/{$branch['id']}/assign", $owner, ['userId' => (string) $coach->getId()]);

        $result = $this->request('DELETE', "/branches/{$branch['id']}", $owner);

        self::assertSame(204, $result['status']);
        $rowCount = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM branch_assignment WHERE branch_id = ?',
            [$branch['id']],
        );
        self::assertSame('0', (string) $rowCount);
    }

    public function test_deleting_the_primary_branch_is_blocked_409(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $primary = $this->request('GET', '/branches', $owner)['body']['branches'][0];

        $result = $this->request('DELETE', "/branches/{$primary['id']}", $owner);

        self::assertSame(409, $result['status']);
        self::assertSame('primary_branch', $result['body']['error']);
    }

    public function test_deleting_a_branch_with_a_membership_plan_is_blocked_409(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Downtown Plan', 'price' => '49.99', 'durationDays' => 30, 'features' => [], 'branchId' => $branch['id'],
        ]);

        $result = $this->request('DELETE', "/branches/{$branch['id']}", $owner);

        self::assertSame(409, $result['status']);
        self::assertSame('branch_in_use', $result['body']['error']);
    }

    public function test_deleting_a_branch_with_attendance_history_is_blocked_409(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $member = $this->createUser('Mia Member', UserRole::MEMBER);
        $this->em->persist(new MemberProfile($member));
        $this->em->flush();
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Downtown Plan', 'price' => '49.99', 'durationDays' => 30, 'features' => [], 'branchId' => $branch['id'],
        ])['body'];
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['id']]);
        $this->request('POST', "/members/{$member->getId()}/checkin", $owner, ['branchId' => $branch['id']]);

        $result = $this->request('DELETE', "/branches/{$branch['id']}", $owner);

        self::assertSame(409, $result['status']);
        self::assertSame('branch_in_use', $result['body']['error']);
    }

    public function test_non_owner_cannot_delete_a_branch_403(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);
        $branch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $result = $this->request('DELETE', "/branches/{$branch['id']}", $staff);

        self::assertSame(403, $result['status']);
    }
}

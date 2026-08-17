<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * roadmap Phase 16.2's signature end-to-end scenario, in one place: two
 * branches, a Staff member assigned to only one. Confirms both halves of
 * the hub-vs-scoped asymmetry that's the entire point of this phase
 * (architecture doc §5.2) —
 *   - CHECK_IN is hub-permissive: Staff can check in a member who
 *     enrolled at (and is "visiting from") the OTHER branch.
 *   - VIEW is branch-scoped: that same visiting member does not appear
 *     in Staff's member list, because they didn't enroll at Staff's
 *     assigned branch.
 * Also includes the paired single-branch regression check: a gym that
 * never creates a second branch must behave exactly as it did before
 * this phase.
 */
final class MultiBranchStaffScopingTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE attendance_log, branch_assignment, branch, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    public function test_staff_can_check_in_a_visiting_member_but_cannot_see_them_in_the_member_list(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        // Two branches: the auto-created primary, plus a second one.
        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        // Staff is assigned to the primary branch ONLY.
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);
        $this->request('POST', "/branches/{$primaryBranchId}/assign", $owner, ['userId' => (string) $staff->getId()]);

        // A member enrolled at Downtown — "visiting" the primary branch today.
        $visitor = $this->createUser('Vera Visitor', UserRole::MEMBER);
        $this->em->persist(new \App\Entity\MemberProfile($visitor));
        $this->em->flush();
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Downtown Plan', 'price' => '49.99', 'durationDays' => 30, 'features' => [], 'branchId' => $downtown['id'],
        ]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $visitor->getId(), 'planId' => $plan['body']['id']]);

        // ---- Half 1: hub model — Staff checks the visitor in at the primary branch, unrestricted by the visitor's enrolling branch.
        $checkIn = $this->request('POST', "/members/{$visitor->getId()}/checkin", $staff, ['branchId' => $primaryBranchId]);
        self::assertSame(201, $checkIn['status'], 'Staff must be able to check in a member enrolled at a different branch (hub model)');
        self::assertSame($primaryBranchId, $checkIn['body']['branchId'], 'the check-in is recorded at the branch Staff is actually working at');

        // ---- Half 2: branch-scoped visibility — that same visitor does not appear in Staff's member list.
        $roster = $this->request('GET', '/members', $staff);
        self::assertSame(200, $roster['status']);
        $rosterIds = array_column($roster['body']['members'], 'id');
        self::assertNotContains(
            (string) $visitor->getId(),
            $rosterIds,
            'a member enrolled at a branch Staff is not assigned to must not appear in Staff\'s member list, even though Staff can check them in',
        );
    }

    /**
     * The paired regression case: a gym that never creates a second
     * branch must behave exactly as it did before this phase — Staff
     * (backfilled onto the one primary branch, or explicitly assigned to
     * it here for a freshly-created account) sees every member, since
     * there is only ever one branch for anyone to be scoped to.
     */
    public function test_single_branch_gym_staff_sees_every_member_unchanged_from_before_phase_16(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];

        $staff = $this->createUser('Sam Staff', UserRole::STAFF);
        $this->request('POST', "/branches/{$primaryBranchId}/assign", $owner, ['userId' => (string) $staff->getId()]);

        $memberOne = $this->createUser('Mia Member', UserRole::MEMBER);
        $memberTwo = $this->createUser('Max Member', UserRole::MEMBER);
        $this->em->persist(new \App\Entity\MemberProfile($memberOne));
        $this->em->persist(new \App\Entity\MemberProfile($memberTwo));
        $this->em->flush();
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Standard', 'price' => '49.99', 'durationDays' => 30, 'features' => []]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $memberOne->getId(), 'planId' => $plan['body']['id']]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $memberTwo->getId(), 'planId' => $plan['body']['id']]);

        $roster = $this->request('GET', '/members', $staff);

        self::assertSame(200, $roster['status']);
        self::assertCount(2, $roster['body']['members'], 'in a single-branch gym, Staff sees every enrolled member, same as pre-Phase-16 behavior');

        // And the front-desk check-in still needs no branchId at all — defaults to the one branch that exists.
        $checkIn = $this->request('POST', "/members/{$memberOne->getId()}/checkin", $staff);
        self::assertSame(201, $checkIn['status']);
        self::assertSame($primaryBranchId, $checkIn['body']['branchId']);
    }
}

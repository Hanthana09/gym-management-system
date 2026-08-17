<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * roadmap Phase 16.2 (Phase 4 retrofit): MembershipPlan.branch_id.
 * Regression case (test_omitting_branch_id_defaults_to_the_primary_branch)
 * is the one that matters most — a single-branch gym's existing
 * POST/GET /membership-plans callers must keep working with zero changes.
 */
final class MembershipPlanBranchScopingTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE membership, membership_plan, branch_assignment, branch, gym, "user" CASCADE',
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

    /** Regression check: a single-branch gym's plan create/list must work exactly as before Phase 16, with no branchId ever specified. */
    public function test_omitting_branch_id_defaults_to_the_primary_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $created = $this->request('POST', '/membership-plans', $owner, ['name' => 'Gold', 'price' => '79.99', 'durationDays' => 30, 'features' => []]);
        self::assertSame(201, $created['status']);

        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        self::assertSame($primaryBranchId, $created['body']['branchId']);

        $listed = $this->request('GET', '/membership-plans', $owner);
        self::assertSame(200, $listed['status']);
        self::assertCount(1, $listed['body']['plans']);
        self::assertSame($primaryBranchId, $listed['body']['branchId']);
    }

    public function test_plans_are_scoped_to_the_branch_they_were_created_for(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $secondBranch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];

        $this->request('POST', '/membership-plans', $owner, ['name' => 'Primary Gold', 'price' => '50', 'durationDays' => 30, 'features' => [], 'branchId' => $primaryBranchId]);
        $this->request('POST', '/membership-plans', $owner, ['name' => 'Downtown Gold', 'price' => '60', 'durationDays' => 30, 'features' => [], 'branchId' => $secondBranch['id']]);

        $primaryPlans = $this->request('GET', "/membership-plans?branchId={$primaryBranchId}", $owner);
        $downtownPlans = $this->request('GET', "/membership-plans?branchId={$secondBranch['id']}", $owner);

        self::assertCount(1, $primaryPlans['body']['plans']);
        self::assertSame('Primary Gold', $primaryPlans['body']['plans'][0]['name']);
        self::assertCount(1, $downtownPlans['body']['plans']);
        self::assertSame('Downtown Gold', $downtownPlans['body']['plans'][0]['name']);
    }

    public function test_a_branch_id_from_a_nonexistent_branch_is_rejected_400(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Gold', 'price' => '79.99', 'durationDays' => 30, 'features' => [],
            'branchId' => '00000000-0000-0000-0000-000000000000',
        ]);

        self::assertSame(400, $result['status']);
    }
}

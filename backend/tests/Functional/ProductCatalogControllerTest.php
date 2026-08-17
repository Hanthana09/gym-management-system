<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * roadmap Phase 17 / functional requirements §15.2 (product catalog).
 * Purely additive (architecture doc §6.13) — never touches `invoice`.
 */
final class ProductCatalogControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE expense, expense_category, product_sale, product, product_category, branch_assignment, branch, invoice, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    private function createUser(string $name, string $email, UserRole $role): User
    {
        $user = new User($name, $email, null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
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
            '/api' . $uri,
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

    private function categoryId(User $owner, string $name = 'Supplements'): string
    {
        $categories = $this->request('GET', '/product-categories', $owner)['body']['categories'];
        foreach ($categories as $category) {
            if ($category['name'] === $name) {
                return $category['id'];
            }
        }
        self::fail("Seeded category '{$name}' not found.");
    }

    // ---- seeded defaults ----------------------------------------------------

    public function test_default_product_categories_are_seeded_on_first_use(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/product-categories', $owner);

        $names = array_column($result['body']['categories'], 'name');
        foreach (['Apparel', 'Supplements', 'Accessories'] as $expected) {
            self::assertContains($expected, $names);
        }
    }

    // ---- category delete -----------------------------------------------------

    public function test_owner_can_delete_an_unused_product_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $created = $this->request('POST', '/product-categories', $owner, ['name' => 'Recovery'])['body'];

        $result = $this->request('DELETE', '/product-categories/' . $created['id'], $owner);

        self::assertSame(204, $result['status']);
        $remaining = array_column($this->request('GET', '/product-categories', $owner)['body']['categories'], 'name');
        self::assertNotContains('Recovery', $remaining);
    }

    public function test_owner_cannot_delete_a_category_that_has_products_in_it(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $categoryId = $this->categoryId($owner);
        $this->request('POST', '/products', $owner, ['categoryId' => $categoryId, 'name' => 'Whey Protein 1kg', 'unitPrice' => '25.00']);

        $result = $this->request('DELETE', '/product-categories/' . $categoryId, $owner);

        self::assertSame(409, $result['status']);
        self::assertSame('category_has_products', $result['body']['error']);
        $remaining = array_column($this->request('GET', '/product-categories', $owner)['body']['categories'], 'id');
        self::assertContains($categoryId, $remaining);
    }

    public function test_staff_gets_a_permission_error_deleting_a_product_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $categoryId = $this->categoryId($owner);

        $result = $this->request('DELETE', '/product-categories/' . $categoryId, $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_coach_and_member_get_a_permission_error_deleting_a_product_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'member@example.com', UserRole::MEMBER);
        $categoryId = $this->categoryId($owner);

        self::assertSame(403, $this->request('DELETE', '/product-categories/' . $categoryId, $coach)['status']);
        self::assertSame(403, $this->request('DELETE', '/product-categories/' . $categoryId, $member)['status']);
    }

    // ---- §15.2 product catalog -----------------------------------------------

    public function test_given_i_create_a_product_with_a_name_category_and_unit_price_when_i_save_it_then_its_immediately_available_for_sale(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $categoryId = $this->categoryId($owner);

        $result = $this->request('POST', '/products', $owner, ['categoryId' => $categoryId, 'name' => 'Whey Protein 1kg', 'unitPrice' => '25.00', 'sku' => 'WP-1']);

        self::assertSame(201, $result['status']);
        self::assertTrue($result['body']['isActive']);

        $catalog = $this->request('GET', '/products?active_only=1', $owner);
        self::assertCount(1, $catalog['body']['products']);
    }

    public function test_given_i_deactivate_a_product_then_it_stops_appearing_in_the_active_picker_but_stays_reportable(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $categoryId = $this->categoryId($owner);
        $product = $this->request('POST', '/products', $owner, ['categoryId' => $categoryId, 'name' => 'Gym Towel', 'unitPrice' => '10.00'])['body'];

        $deactivated = $this->request('PATCH', "/products/{$product['id']}", $owner, ['isActive' => false]);

        self::assertSame(200, $deactivated['status']);
        self::assertFalse($deactivated['body']['isActive']);

        $activeOnly = $this->request('GET', '/products?active_only=1', $owner);
        self::assertCount(0, $activeOnly['body']['products']);
        $fullCatalog = $this->request('GET', '/products', $owner);
        self::assertCount(1, $fullCatalog['body']['products'], 'past product must remain intact and reportable');
    }

    public function test_given_i_am_staff_when_i_view_the_product_catalog_then_i_can_see_it_but_have_no_write_actions(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $categoryId = $this->categoryId($owner);
        $product = $this->request('POST', '/products', $owner, ['categoryId' => $categoryId, 'name' => 'Shaker Bottle', 'unitPrice' => '8.00'])['body'];
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);

        $view = $this->request('GET', '/products', $staff);
        self::assertSame(200, $view['status']);
        self::assertCount(1, $view['body']['products']);

        // "attempting one via a manipulated request is rejected, not just hidden from the UI"
        $createAttempt = $this->request('POST', '/products', $staff, ['categoryId' => $categoryId, 'name' => 'Hijack', 'unitPrice' => '1.00']);
        $editAttempt = $this->request('PATCH', "/products/{$product['id']}", $staff, ['unitPrice' => '999.00']);

        self::assertSame(403, $createAttempt['status']);
        self::assertSame(403, $editAttempt['status']);
    }

    public function test_given_i_am_a_coach_or_member_when_i_attempt_to_access_the_product_catalog_via_any_route_then_i_get_a_permission_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $categoryId = $this->categoryId($owner);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        foreach ([$coach, $member] as $intruder) {
            self::assertSame(403, $this->request('GET', '/products', $intruder)['status']);
            self::assertSame(403, $this->request('POST', '/products', $intruder, ['categoryId' => $categoryId, 'name' => 'X', 'unitPrice' => '1.00'])['status']);
            self::assertSame(403, $this->request('GET', '/product-categories', $intruder)['status']);
            self::assertSame(403, $this->request('POST', '/product-categories', $intruder, ['name' => 'X'])['status']);
        }
    }
}

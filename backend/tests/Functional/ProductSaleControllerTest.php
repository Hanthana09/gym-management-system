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
 * roadmap Phase 17 / functional requirements §15.3. Purely additive
 * (architecture doc §6.13) — `ProductSale.member_id` is filtering/reporting
 * only and never touches `membership`/`invoice`; no test here asserts
 * against the `invoice` table/entity.
 */
final class ProductSaleControllerTest extends WebTestCase
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

    private function primaryBranchId(User $owner): string
    {
        return $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
    }

    private function createProduct(User $owner, string $price = '15.00'): array
    {
        $categoryId = $this->request('GET', '/product-categories', $owner)['body']['categories'][0]['id'];

        return $this->request('POST', '/products', $owner, ['categoryId' => $categoryId, 'name' => 'Gym T-Shirt', 'unitPrice' => $price])['body'];
    }

    private function assignStaffToBranch(User $owner, User $staff, string $branchId): void
    {
        $this->request('POST', "/branches/{$branchId}/assign", $owner, ['userId' => (string) $staff->getId()]);
    }

    // ---- §15.3 recording a sale ---------------------------------------------

    public function test_given_i_select_a_product_and_quantity_when_i_save_the_sale_then_the_total_is_computed_automatically(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $product = $this->createProduct($owner, '15.00');

        $result = $this->request('POST', '/product-sales', $owner, [
            'branchId' => $branchId, 'productId' => $product['id'], 'quantity' => 3, 'paymentMethod' => 'cash',
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame('15.00', $result['body']['unitPriceAtSale']);
        self::assertSame('45.00', $result['body']['totalAmount']);
    }

    /** functional requirements §15.3: "a later catalog price change never changes this sale's recorded figures." */
    public function test_a_later_catalog_price_change_never_rewrites_a_past_sales_recorded_figures(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $product = $this->createProduct($owner, '15.00');
        $sale = $this->request('POST', '/product-sales', $owner, [
            'branchId' => $branchId, 'productId' => $product['id'], 'quantity' => 2, 'paymentMethod' => 'cash',
        ])['body'];

        $this->request('PATCH', "/products/{$product['id']}", $owner, ['unitPrice' => '99.00']);

        $sales = $this->request('GET', '/product-sales', $owner)['body']['sales'];
        $found = current(array_filter($sales, fn ($s) => $s['id'] === $sale['id']));
        self::assertSame('15.00', $found['unitPriceAtSale']);
        self::assertSame('30.00', $found['totalAmount']);
    }

    public function test_given_i_optionally_attach_an_existing_member_to_the_sale_then_its_linked_for_reporting_only(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $product = $this->createProduct($owner);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/product-sales', $owner, [
            'branchId' => $branchId, 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'card', 'memberId' => (string) $member->getId(),
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame((string) $member->getId(), $result['body']['member']['id']);
    }

    /** functional requirements §15.3: "no member is attached (a walk-in sale)... recorded successfully with no error." */
    public function test_given_no_member_is_attached_a_walk_in_sale_then_it_is_recorded_successfully(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $product = $this->createProduct($owner);

        $result = $this->request('POST', '/product-sales', $owner, [
            'branchId' => $branchId, 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'cash',
        ]);

        self::assertSame(201, $result['status']);
        self::assertNull($result['body']['member']);
    }

    /** functional requirements §15.3: "the member search... returns no match... I cannot create a new member record from this screen." */
    public function test_given_the_member_search_returns_no_match_a_nonexistent_member_id_is_rejected_not_silently_created(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $product = $this->createProduct($owner);

        $result = $this->request('POST', '/product-sales', $owner, [
            'branchId' => $branchId, 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'cash',
            'memberId' => '00000000-0000-0000-0000-000000000000',
        ]);

        self::assertSame(400, $result['status']);
    }

    // ---- §15.3 Staff scoping -----------------------------------------------

    public function test_given_i_am_staff_when_i_record_a_sale_then_i_can_only_do_so_for_a_branch_im_assigned_to(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $primaryBranchId = $this->primaryBranchId($owner);
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $product = $this->createProduct($owner);
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->assignStaffToBranch($owner, $staff, $primaryBranchId);

        $atOwnBranch = $this->request('POST', '/product-sales', $staff, [
            'branchId' => $primaryBranchId, 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'cash',
        ]);
        $atOtherBranch = $this->request('POST', '/product-sales', $staff, [
            'branchId' => $downtown['id'], 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'cash',
        ]);

        self::assertSame(201, $atOwnBranch['status']);
        self::assertSame(403, $atOtherBranch['status']);
    }

    // ---- §15.3 Coach/Member: no access at all ------------------------------

    public function test_given_i_am_a_coach_or_member_when_i_attempt_to_record_or_view_retail_sales_via_any_route_then_i_get_a_permission_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $product = $this->createProduct($owner);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        foreach ([$coach, $member] as $intruder) {
            self::assertSame(403, $this->request('GET', '/product-sales', $intruder)['status']);
            self::assertSame(403, $this->request('POST', '/product-sales', $intruder, [
                'branchId' => $branchId, 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'cash',
            ])['status']);
        }
    }

    // ---- branch-scoping rollup ----------------------------------------------

    public function test_owner_querying_one_branch_does_not_see_another_branchs_sales(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $primaryBranchId = $this->primaryBranchId($owner);
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $product = $this->createProduct($owner);
        $this->request('POST', '/product-sales', $owner, ['branchId' => $primaryBranchId, 'productId' => $product['id'], 'quantity' => 1, 'paymentMethod' => 'cash']);
        $this->request('POST', '/product-sales', $owner, ['branchId' => $downtown['id'], 'productId' => $product['id'], 'quantity' => 2, 'paymentMethod' => 'cash']);

        $primaryOnly = $this->request('GET', "/product-sales?branch_id={$primaryBranchId}", $owner);
        $rollup = $this->request('GET', '/product-sales', $owner);

        self::assertCount(1, $primaryOnly['body']['sales']);
        self::assertCount(2, $rollup['body']['sales']);
    }
}

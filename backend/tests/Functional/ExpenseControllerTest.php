<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * roadmap Phase 17 / functional requirements §15.1. This phase is purely
 * additive (architecture doc §6.13) — no test in this file touches or
 * asserts against the `invoice` table/entity; TRUNCATE below lists it
 * only because other Functional tests' fixtures share the same base
 * tables (Owner/Gym/Branch), never because Expense writes to it.
 */
final class ExpenseControllerTest extends WebTestCase
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

    private function authHeaders(User $user): array
    {
        return ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($user)];
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            '/api' . $uri,
            server: array_merge(['CONTENT_TYPE' => 'application/json'], $this->authHeaders($actingAs)),
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        return $this->response();
    }

    /** Multipart, for the expense create endpoint (optional receipt upload). */
    private function requestMultipart(string $method, string $uri, User $actingAs, array $params = [], array $files = []): array
    {
        $this->client->request($method, '/api' . $uri, $params, $files, $this->authHeaders($actingAs));

        return $this->response();
    }

    private function response(): array
    {
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

    private function categoryId(User $owner, string $name = 'Utilities'): string
    {
        $categories = $this->request('GET', '/expense-categories', $owner)['body']['categories'];
        foreach ($categories as $category) {
            if ($category['name'] === $name) {
                return $category['id'];
            }
        }
        self::fail("Seeded category '{$name}' not found.");
    }

    private function assignStaffToBranch(User $owner, User $staff, string $branchId): void
    {
        $this->request('POST', "/branches/{$branchId}/assign", $owner, ['userId' => (string) $staff->getId()]);
    }

    // ---- §15.1 default categories are seeded -----------------------------

    public function test_default_expense_categories_are_seeded_on_first_use(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/expense-categories', $owner);

        $names = array_column($result['body']['categories'], 'name');
        self::assertSame(200, $result['status']);
        foreach (['Utilities', 'Rent', 'Equipment', 'Maintenance', 'Salaries', 'Other'] as $expected) {
            self::assertContains($expected, $names);
        }
    }

    public function test_owner_can_add_a_custom_expense_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/expense-categories', $owner, ['name' => 'Marketing']);

        self::assertSame(201, $result['status']);
        self::assertSame('Marketing', $result['body']['name']);
    }

    public function test_owner_can_delete_an_unused_expense_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $created = $this->request('POST', '/expense-categories', $owner, ['name' => 'Marketing']);

        $result = $this->request('DELETE', '/expense-categories/' . $created['body']['id'], $owner);

        self::assertSame(204, $result['status']);
        $remaining = array_column($this->request('GET', '/expense-categories', $owner)['body']['categories'], 'name');
        self::assertNotContains('Marketing', $remaining);
    }

    public function test_owner_cannot_delete_a_category_that_has_expenses_recorded_against_it(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);
        $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '20.00', 'expenseDate' => '2026-01-01',
        ]);

        $result = $this->request('DELETE', '/expense-categories/' . $categoryId, $owner);

        self::assertSame(409, $result['status']);
        self::assertSame('category_has_expenses', $result['body']['error']);
        $remaining = array_column($this->request('GET', '/expense-categories', $owner)['body']['categories'], 'id');
        self::assertContains($categoryId, $remaining);
    }

    public function test_staff_gets_a_permission_error_deleting_an_expense_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $categoryId = $this->categoryId($owner);

        $result = $this->request('DELETE', '/expense-categories/' . $categoryId, $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_coach_and_member_get_a_permission_error_deleting_an_expense_category(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'member@example.com', UserRole::MEMBER);
        $categoryId = $this->categoryId($owner);

        self::assertSame(403, $this->request('DELETE', '/expense-categories/' . $categoryId, $coach)['status']);
        self::assertSame(403, $this->request('DELETE', '/expense-categories/' . $categoryId, $member)['status']);
    }

    // ---- §15.1 recording an expense ---------------------------------------

    public function test_given_i_submit_an_expense_with_a_positive_amount_a_category_a_branch_and_a_date_when_i_save_it_then_it_appears_immediately_in_the_expense_list_for_that_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);

        $result = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId,
            'categoryId' => $categoryId,
            'amount' => '150.00',
            'expenseDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'description' => 'Electricity bill',
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame('150.00', $result['body']['amount']);

        $list = $this->request('GET', "/expenses?branch_id={$branchId}", $owner);
        self::assertCount(1, $list['body']['expenses']);
        self::assertSame('150.00', $list['body']['expenses'][0]['amount']);
    }

    public function test_given_i_submit_an_expense_with_a_zero_or_negative_amount_when_i_try_to_save_then_i_see_a_specific_validation_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);

        $zero = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '0', 'expenseDate' => '2026-01-01',
        ]);
        $negative = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '-50', 'expenseDate' => '2026-01-01',
        ]);

        self::assertSame(400, $zero['status']);
        self::assertSame('invalid_request', $zero['body']['error']);
        self::assertSame(400, $negative['status']);
    }

    public function test_given_missing_category_or_branch_when_i_try_to_save_then_i_see_a_specific_validation_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);

        $noCategory = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'amount' => '10.00', 'expenseDate' => '2026-01-01',
        ]);
        $noBranch = $this->requestMultipart('POST', '/expenses', $owner, [
            'categoryId' => $categoryId, 'amount' => '10.00', 'expenseDate' => '2026-01-01',
        ]);

        self::assertSame(400, $noCategory['status']);
        self::assertSame(400, $noBranch['status']);
    }

    // ---- §15.1 Staff scoping -----------------------------------------------

    public function test_given_i_am_staff_when_i_record_an_expense_then_i_can_only_do_so_for_a_branch_im_assigned_to(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $primaryBranchId = $this->primaryBranchId($owner);
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $categoryId = $this->categoryId($owner);
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->assignStaffToBranch($owner, $staff, $primaryBranchId);

        $atOwnBranch = $this->requestMultipart('POST', '/expenses', $staff, [
            'branchId' => $primaryBranchId, 'categoryId' => $categoryId, 'amount' => '20.00', 'expenseDate' => '2026-01-01',
        ]);
        $atOtherBranch = $this->requestMultipart('POST', '/expenses', $staff, [
            'branchId' => $downtown['id'], 'categoryId' => $categoryId, 'amount' => '20.00', 'expenseDate' => '2026-01-01',
        ]);

        self::assertSame(201, $atOwnBranch['status']);
        self::assertSame(403, $atOtherBranch['status']);
    }

    public function test_given_i_am_staff_and_try_to_edit_or_delete_an_existing_expense_including_one_i_created_then_i_get_a_permission_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->assignStaffToBranch($owner, $staff, $branchId);
        $created = $this->requestMultipart('POST', '/expenses', $staff, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '20.00', 'expenseDate' => '2026-01-01',
        ]);
        $expenseId = $created['body']['id'];

        $editAttempt = $this->request('PATCH', "/expenses/{$expenseId}", $staff, ['amount' => '99.00']);
        $deleteAttempt = $this->request('DELETE', "/expenses/{$expenseId}", $staff);

        self::assertSame(403, $editAttempt['status']);
        self::assertSame(403, $deleteAttempt['status']);
    }

    public function test_owner_can_edit_and_delete_any_expense(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);
        $created = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '20.00', 'expenseDate' => '2026-01-01',
        ]);
        $expenseId = $created['body']['id'];

        $edited = $this->request('PATCH', "/expenses/{$expenseId}", $owner, ['amount' => '99.00']);
        self::assertSame(200, $edited['status']);
        self::assertSame('99.00', $edited['body']['amount']);

        $deleted = $this->request('DELETE', "/expenses/{$expenseId}", $owner);
        self::assertSame(204, $deleted['status']);
        $list = $this->request('GET', '/expenses', $owner);
        self::assertCount(0, $list['body']['expenses']);
    }

    // ---- §15.1 Coach/Member: no access at all -----------------------------

    public function test_given_i_am_a_coach_or_member_when_i_attempt_to_view_create_edit_or_delete_an_expense_via_any_route_then_i_get_a_permission_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $existing = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '20.00', 'expenseDate' => '2026-01-01',
        ])['body'];

        foreach ([$coach, $member] as $intruder) {
            self::assertSame(403, $this->request('GET', '/expenses', $intruder)['status']);
            self::assertSame(403, $this->requestMultipart('POST', '/expenses', $intruder, [
                'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '10.00', 'expenseDate' => '2026-01-01',
            ])['status']);
            self::assertSame(403, $this->request('PATCH', "/expenses/{$existing['id']}", $intruder, ['amount' => '1.00'])['status']);
            self::assertSame(403, $this->request('DELETE', "/expenses/{$existing['id']}", $intruder)['status']);
            self::assertSame(403, $this->request('GET', '/expense-categories', $intruder)['status']);
        }
    }

    // ---- §15.1 receipt upload ----------------------------------------------

    private function tempReceipt(): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $path = tempnam(sys_get_temp_dir(), 'receipt') . '.png';
        file_put_contents($path, $pngBytes);

        return new UploadedFile($path, 'receipt.png', 'image/png', null, true);
    }

    public function test_given_i_attach_a_receipt_to_an_expense_when_i_save_it_then_its_stored_as_a_simple_file_upload(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->primaryBranchId($owner);
        $categoryId = $this->categoryId($owner);

        $result = $this->requestMultipart('POST', '/expenses', $owner, [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => '75.00', 'expenseDate' => '2026-01-01',
        ], ['receipt' => $this->tempReceipt()]);

        self::assertSame(201, $result['status']);
        self::assertStringStartsWith('/uploads/expense-receipts/', $result['body']['receiptUrl']);
    }
}

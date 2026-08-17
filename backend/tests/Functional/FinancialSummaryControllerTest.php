<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * roadmap Phase 17 / functional requirements §15.4. This is the phase's
 * central integration point — membership (via the EXISTING Invoice
 * enroll+mark-paid flow, used here purely as test-data setup, never
 * asserted against directly — no test in this class or file touches
 * `invoice` as a subject of assertion) + PT (derived, read-time estimate)
 * + retail + expenses, netted against each other.
 */
final class FinancialSummaryControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        // Several tests here interleave direct EntityManager persists (coach
        // hourly_rate, which has no HTTP-reachable setter anywhere in this
        // codebase) with HTTP requests — disabling the default per-request
        // kernel reboot keeps $this->em valid across both, same fix this
        // codebase already applies in PtSessionControllerTest/AttendanceControllerTest/etc.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE expense, expense_category, product_sale, product, product_category, pt_session, branch_assignment, branch, invoice, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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

    private function todayRange(): string
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return "from={$today}&to={$today}";
    }

    /** Membership revenue via the EXISTING enroll+mark-paid flow — setup only, never asserted against Invoice directly. */
    private function recordMembershipRevenue(User $owner, User $member, string $branchId, string $price): void
    {
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Standard', 'price' => $price, 'durationDays' => 30, 'features' => [], 'branchId' => $branchId,
        ]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];
        $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'cash']);
    }

    /**
     * PT revenue is a derived, read-time estimate — Σ (hourly_rate ×
     * duration/60) over confirmed|completed sessions. Takes a branch ID
     * (not a Branch object) and re-fetches it fresh right before use —
     * Doctrine's EntityManager identity map gets reset between simulated
     * HTTP requests in these WebTestCase tests (a resetter on
     * kernel.terminate, independent of $client->disableReboot(), which
     * only stops the kernel itself from rebooting), so an entity object
     * captured before an earlier `$this->request()` call is stale by the
     * time it's used here.
     */
    private function recordConfirmedPtSession(User $owner, User $member, string $branchId, string $hourlyRate, int $durationMinutes): void
    {
        $branch = $this->em->getRepository(Branch::class)->find($branchId);
        $coach = $this->createUser('Carlos Coach', 'coach-' . bin2hex(random_bytes(4)) . '@example.com', UserRole::COACH);
        $profile = new CoachProfile($coach);
        $profile->setHourlyRate($hourlyRate);
        $this->em->persist($profile);
        $this->em->persist(new BranchAssignment($coach, $branch));
        $this->em->flush();

        $created = $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+2 hours'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $durationMinutes,
            'branchId' => $branchId,
        ]);
        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);
    }

    private function recordRetailSale(User $owner, string $branchId, string $unitPrice, int $quantity): void
    {
        $categoryId = $this->request('GET', '/product-categories', $owner)['body']['categories'][0]['id'];
        $product = $this->request('POST', '/products', $owner, ['categoryId' => $categoryId, 'name' => 'Gym T-Shirt', 'unitPrice' => $unitPrice])['body'];
        $this->request('POST', '/product-sales', $owner, ['branchId' => $branchId, 'productId' => $product['id'], 'quantity' => $quantity, 'paymentMethod' => 'cash']);
    }

    private function recordExpense(User $owner, string $branchId, string $amount): void
    {
        $categoryId = $this->request('GET', '/expense-categories', $owner)['body']['categories'][0]['id'];
        $this->client->request('POST', '/api/expenses', [
            'branchId' => $branchId, 'categoryId' => $categoryId, 'amount' => $amount, 'expenseDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ], [], ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($owner)]);
    }

    // ---- §15.4 math correctness ---------------------------------------------

    public function test_financial_summary_math_membership_plus_pt_plus_retail_minus_expenses_equals_net(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $branchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];

        $this->recordMembershipRevenue($owner, $member, $branchId, '100.00');       // membership = 100.00
        $this->recordConfirmedPtSession($owner, $member, $branchId, '20.00', 90);    // pt = 20.00 * 90/60 = 30.00
        $this->recordRetailSale($owner, $branchId, '10.00', 2);                      // retail = 20.00
        $this->recordExpense($owner, $branchId, '15.00');                            // expenses = 15.00

        $result = $this->request('GET', '/financial-summary?' . $this->todayRange(), $owner);

        self::assertSame(200, $result['status']);
        self::assertSame('100.00', $result['body']['membershipRevenue']);
        self::assertSame('30.00', $result['body']['ptRevenue']);
        self::assertSame('20.00', $result['body']['retailRevenue']);
        self::assertSame('15.00', $result['body']['totalExpenses']);
        // net = 100 + 30 + 20 - 15 = 135.00
        self::assertSame('135.00', $result['body']['net']);
    }

    public function test_a_pending_unconfirmed_pt_session_does_not_count_toward_pt_revenue(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $branchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $branch = $this->em->getRepository(Branch::class)->find($branchId);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $profile = new CoachProfile($coach);
        $profile->setHourlyRate('50.00');
        $this->em->persist($profile);
        $this->em->persist(new BranchAssignment($coach, $branch));
        $this->em->flush();
        $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+2 hours'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => 60,
            'branchId' => $branchId,
        ]);
        // left pending — never confirmed.

        $result = $this->request('GET', '/financial-summary?' . $this->todayRange(), $owner);

        self::assertSame('0.00', $result['body']['ptRevenue']);
    }

    // ---- §15.4 branch scoping -------------------------------------------------

    /** functional requirements §15.4: "select a specific branch... every figure reflects only that branch's activity." */
    public function test_multi_branch_owner_selecting_one_branch_sees_only_that_branchs_figures(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $this->recordExpense($owner, $primaryBranchId, '10.00');
        $this->recordExpense($owner, $downtown['id'], '25.00');
        $this->recordRetailSale($owner, $primaryBranchId, '5.00', 1);
        $this->recordRetailSale($owner, $downtown['id'], '5.00', 3);

        $primaryOnly = $this->request('GET', "/financial-summary?branch_id={$primaryBranchId}&" . $this->todayRange(), $owner);
        $downtownOnly = $this->request('GET', "/financial-summary?branch_id={$downtown['id']}&" . $this->todayRange(), $owner);
        $rollup = $this->request('GET', '/financial-summary?' . $this->todayRange(), $owner);

        self::assertSame('10.00', $primaryOnly['body']['totalExpenses']);
        self::assertSame('5.00', $primaryOnly['body']['retailRevenue']);
        self::assertSame('25.00', $downtownOnly['body']['totalExpenses']);
        self::assertSame('15.00', $downtownOnly['body']['retailRevenue']);
        // omitting branch_id must roll up both branches, not just one
        self::assertSame('35.00', $rollup['body']['totalExpenses']);
        self::assertSame('20.00', $rollup['body']['retailRevenue']);
    }

    public function test_an_unknown_branch_id_is_rejected_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/financial-summary?branch_id=00000000-0000-0000-0000-000000000000', $owner);

        self::assertSame(400, $result['status']);
    }

    // ---- §15.4 permission -------------------------------------------------

    /** functional requirements §15.4: "I am Staff, Coach, or Member... any route... permission error." */
    public function test_given_i_am_staff_coach_or_member_when_i_attempt_to_view_the_financial_summary_then_i_get_a_permission_error(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->request('GET', '/branches', $owner); // provisions the gym
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        foreach ([$staff, $coach, $member] as $intruder) {
            $result = $this->request('GET', '/financial-summary', $intruder);
            self::assertSame(403, $result['status']);
        }
    }
}

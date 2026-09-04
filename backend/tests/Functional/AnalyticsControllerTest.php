<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Reporting\DailyMetricAggregator;
use App\Repository\GymRepository;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Home-dashboard chart endpoints — the Owner analytics slice of roadmap
 * Phase 11. Mirrors ReportControllerTest's setup: single-gym test DB,
 * JWT-authenticated requests, DailyMetricAggregator::backfill() to
 * populate DAILY_METRIC_SNAPSHOT from historical rows.
 *
 * Access control: these endpoints reuse ReportVoter::VIEW (Owner owns the
 * Gym). ReportVoter itself is unit-tested in tests/Security/Voter/
 * ReportVoterTest.php (one pass, one 403); the cases here are the
 * functional 403s for Coach / Staff / Member across every route, plus the
 * prompt's edge cases (empty series, zero-checkin grid, hub-wide branch
 * param rejection, invalid branch id).
 */
final class AnalyticsControllerTest extends WebTestCase
{
    private const ENDPOINTS = [
        '/v1/analytics/revenue',
        '/v1/analytics/membership-health',
        '/v1/analytics/peak-hours',
        '/v1/analytics/branch-comparison',
        '/v1/analytics/at-risk-members',
        '/v1/analytics/new-vs-returning',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE audit_log, daily_metric_snapshot, invoice, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

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

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            '/api' . $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(TokenIssuer::class)->createAccessToken($actingAs),
            ],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function createPlanAndEnroll(User $owner, User $member, string $price = '49.99'): array
    {
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Standard',
            'price' => $price,
            'durationDays' => 30,
            'features' => [],
        ]);
        $enrolled = $this->request('POST', '/memberships', $owner, [
            'memberUserId' => (string) $member->getId(),
            'planId' => $plan['body']['id'],
        ]);

        return $enrolled['body'];
    }

    private function backfill(): void
    {
        $gym = static::getContainer()->get(GymRepository::class)->findTheOnlyGym();
        static::getContainer()->get(DailyMetricAggregator::class)->aggregate($gym, new \DateTimeImmutable('today'));
        static::getContainer()->get(DailyMetricAggregator::class)->backfill($gym);
    }

    // ---- access control ----------------------------------------------------

    /** Negative test cases 1 & 2: Coach / Staff / Member get 403 on every analytics endpoint. */
    public function test_non_owner_roles_are_forbidden_from_every_endpoint(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        foreach (self::ENDPOINTS as $endpoint) {
            foreach ([$coach, $staff, $member] as $user) {
                $result = $this->request('GET', $endpoint, $user);
                self::assertSame(403, $result['status'], "$endpoint should 403 for {$user->getRole()->value}");
            }
        }
    }

    public function test_unauthenticated_request_is_401(): void
    {
        $this->client->request('GET', '/api/v1/analytics/revenue', server: ['HTTPS' => 'on']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** Negative test case 4 (single-gym adaptation): a branch id that isn't one of this gym's branches is a 400, same response whether it exists elsewhere or not — nothing leaks. */
    public function test_unknown_branch_id_is_rejected(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/v1/analytics/membership-health?branch_id=' . \Symfony\Component\Uid\Uuid::v7(), $owner);

        self::assertSame(400, $result['status']);
    }

    /** Negative test case 3: branch-comparison is hub-wide; a branch_id param is rejected, not silently ignored. */
    public function test_branch_comparison_rejects_a_branch_id_param(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/v1/analytics/branch-comparison?branch_id=' . \Symfony\Component\Uid\Uuid::v7(), $owner);

        self::assertSame(400, $result['status']);
    }

    // ---- revenue ---------------------------------------------------------

    public function test_revenue_daily_and_monthly_series_reflect_a_paid_invoice(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member, '60.00');
        $invoiceId = $this->em->getConnection()->fetchOne('SELECT id FROM invoice LIMIT 1');
        $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'cash']);
        $this->backfill();

        $daily = $this->request('GET', '/v1/analytics/revenue?from=' . (new \DateTimeImmutable('-3 days'))->format('Y-m-d'), $owner);
        self::assertSame(200, $daily['status']);
        self::assertSame('daily', $daily['body']['granularity']);
        $todaysRow = array_column($daily['body']['series'], 'revenue', 'period')[(new \DateTimeImmutable('today'))->format('Y-m-d')] ?? null;
        self::assertSame('60.00', $todaysRow);

        $monthly = $this->request('GET', '/v1/analytics/revenue?granularity=monthly', $owner);
        self::assertSame(200, $monthly['status']);
        self::assertSame('monthly', $monthly['body']['granularity']);
        $thisMonth = array_column($monthly['body']['series'], 'revenue', 'period')[(new \DateTimeImmutable('today'))->format('Y-m')] ?? null;
        self::assertSame('60.00', $thisMonth);
    }

    /** Negative test case 5: a monthly range with no snapshot rows yet returns an empty series with 200, never a 500. */
    public function test_revenue_monthly_with_no_snapshots_is_empty_series_200(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request(
            'GET',
            '/v1/analytics/revenue?granularity=monthly&from=2020-01-01&to=2020-12-31',
            $owner,
        );

        self::assertSame(200, $result['status']);
        self::assertSame([], $result['body']['series']);
    }

    // ---- membership health ---------------------------------------------------

    public function test_membership_health_returns_all_buckets_and_counts_an_active_member(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('GET', '/v1/analytics/membership-health', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame(
            ['active', 'expiring', 'expired', 'paused', 'suspended', 'cancelled'],
            array_keys($result['body']['buckets']),
        );
        // A fresh 30-day enrollment: active OR expiring (if durationDays <= 7 window), never expired.
        self::assertSame(1, $result['body']['buckets']['active'] + $result['body']['buckets']['expiring']);
        self::assertSame(0, $result['body']['buckets']['expired']);
    }

    // ---- peak hours --------------------------------------------------------

    /** Negative test case 6: zero check-ins in the window -> an all-zero 7x24 grid, maxCount 0, no crash. */
    public function test_peak_hours_with_no_checkins_is_an_all_zero_grid(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/v1/analytics/peak-hours?days=30', $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(7 * 24, $result['body']['grid']);
        self::assertSame(0, $result['body']['maxCount']);
        self::assertSame(0, $result['body']['totalCheckins']);
        self::assertSame(0, array_sum(array_column($result['body']['grid'], 'count')));
    }

    public function test_peak_hours_buckets_a_checkin_by_day_of_week_and_hour(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);
        $this->em->getConnection()->executeStatement(
            "UPDATE attendance_log SET check_in = date_trunc('day', now()) + interval '9 hours'",
        );

        $result = $this->request('GET', '/v1/analytics/peak-hours?days=7', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['body']['maxCount']);
        self::assertSame(1, $result['body']['totalCheckins']);
        $today = (int) (new \DateTimeImmutable('today'))->format('w');
        $cell = array_values(array_filter(
            $result['body']['grid'],
            static fn (array $c) => $c['dayOfWeek'] === $today && $c['hour'] === 9,
        ));
        self::assertSame(1, $cell[0]['count']);
    }

    // ---- branch comparison ------------------------------------------------

    public function test_branch_comparison_returns_per_branch_rows(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member, '75.00');
        $invoiceId = $this->em->getConnection()->fetchOne('SELECT id FROM invoice LIMIT 1');
        $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'cash']);
        // Revenue is realized on invoice.paid_at (InvoiceRepository::sumPaidAmountOnDate).
        // Move it to a historical day so backfill() (which stops at yesterday) records it.
        $this->em->getConnection()->executeStatement(
            "UPDATE invoice SET paid_at = now() - interval '3 days' WHERE id = ?",
            [$invoiceId],
        );
        $this->backfill();

        $result = $this->request('GET', '/v1/analytics/branch-comparison?period=30d', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame('30d', $result['body']['period']);
        self::assertNotEmpty($result['body']['branches']);
        $row = $result['body']['branches'][0];
        self::assertArrayHasKey('branchName', $row);
        self::assertArrayHasKey('revenue', $row);
        self::assertArrayHasKey('attendanceCount', $row);
        self::assertArrayHasKey('activeMembers', $row);
    }

    // ---- at-risk trend ---------------------------------------------------

    public function test_at_risk_trend_has_one_point_per_week(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/v1/analytics/at-risk-members?weeks=8', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame(8, $result['body']['weeks']);
        self::assertCount(8, $result['body']['trend']);
        self::assertArrayHasKey('weekEnding', $result['body']['trend'][0]);
        self::assertArrayHasKey('count', $result['body']['trend'][0]);
        self::assertIsInt($result['body']['current']);
    }

    // ---- new vs returning ------------------------------------------------

    public function test_new_vs_returning_counts_a_first_time_member_as_new(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('GET', '/v1/analytics/new-vs-returning', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame('monthly', $result['body']['granularity']);
        $thisMonth = array_values(array_filter(
            $result['body']['series'],
            static fn (array $r) => $r['period'] === (new \DateTimeImmutable('today'))->format('Y-m'),
        ));
        self::assertSame(1, $thisMonth[0]['new']);
        self::assertSame(0, $thisMonth[0]['returning']);
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AuditLogRepository;
use App\Reporting\DailyMetricAggregator;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * roadmap Phase 11 / functional requirements §10.1–10.5. Also carries
 * §4.2's attendance-report criteria, which moved here from
 * AttendanceControllerTest when /reports/attendance moved into
 * ReportController (see that controller's docblock).
 */
final class ReportControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
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

    private function createUser(string $name, string $email, UserRole $role, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = new User($name, $email, null, $role, $status);
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

    /** For non-JSON responses (export). */
    private function requestRaw(string $uri, User $actingAs): array
    {
        $this->client->request(
            'GET',
            $uri,
            server: [
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($actingAs),
            ],
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'contentType' => $response->headers->get('Content-Type'),
            'contentDisposition' => $response->headers->get('Content-Disposition'),
            'body' => $response->getContent(),
        ];
    }

    private function createPlanAndEnroll(User $owner, User $member, string $price = '49.99', int $durationDays = 30): array
    {
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Standard',
            'price' => $price,
            'durationDays' => $durationDays,
            'features' => [],
        ]);
        $enrolled = $this->request('POST', '/memberships', $owner, [
            'memberUserId' => (string) $member->getId(),
            'planId' => $plan['body']['id'],
        ]);

        return $enrolled['body'];
    }

    // ---- §10.1 Live business dashboard --------------------------------------

    public function test_dashboard_shows_todays_checkins_revenue_and_active_members(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $membership = $this->createPlanAndEnroll($owner, $member, '60.00');
        $this->request('PATCH', "/invoices/{$this->latestInvoiceId($owner)}/mark-paid", $owner, ['paymentMethod' => 'cash']);
        $this->request('POST', '/members/me/checkin', $member);

        $result = $this->request('GET', '/reports/dashboard', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['body']['todayCheckins']);
        self::assertSame('60.00', $result['body']['todayRevenue']);
        self::assertSame(1, $result['body']['activeMembersCount']);
    }

    public function test_non_owner_cannot_view_dashboard_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/reports/dashboard', $member);

        self::assertSame(403, $result['status']);
    }

    // ---- §4.2 (moved) / §10.2 Attendance trend ------------------------------

    public function test_given_date_range_filter_when_viewing_report_then_only_matching_entries_returned(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);

        $checkInId = $this->em->getConnection()->fetchOne('SELECT id FROM attendance_log LIMIT 1');
        $this->em->getConnection()->executeStatement(
            "UPDATE attendance_log SET check_in = current_date - interval '10 days' WHERE id = ?",
            [$checkInId],
        );

        $inRange = $this->request(
            'GET',
            '/reports/attendance?from=' . (new \DateTimeImmutable('-15 days'))->format('Y-m-d')
                . '&to=' . (new \DateTimeImmutable('-5 days'))->format('Y-m-d'),
            $owner,
        );
        $outOfRange = $this->request(
            'GET',
            '/reports/attendance?from=' . (new \DateTimeImmutable('-4 days'))->format('Y-m-d')
                . '&to=' . (new \DateTimeImmutable())->format('Y-m-d'),
            $owner,
        );

        self::assertSame(200, $inRange['status']);
        self::assertCount(1, $inRange['body']['entries']);
        self::assertSame('Mia Member', $inRange['body']['entries'][0]['memberName']);

        self::assertSame(200, $outOfRange['status']);
        self::assertCount(0, $outOfRange['body']['entries']);
    }

    public function test_non_owner_cannot_view_attendance_report_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/reports/attendance', $member);

        self::assertSame(403, $result['status']);
    }

    /** functional requirements §10.2: "the range spans a period before this feature existed... accurate historical data, not a gap." */
    public function test_attendance_trend_reflects_backfilled_history_not_a_gap(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);
        $checkInId = $this->em->getConnection()->fetchOne('SELECT id FROM attendance_log LIMIT 1');
        $historicalDay = (new \DateTimeImmutable('-8 days'))->format('Y-m-d');
        $this->em->getConnection()->executeStatement(
            'UPDATE attendance_log SET check_in = ? WHERE id = ?',
            [$historicalDay . ' 10:00:00', $checkInId],
        );

        $gym = static::getContainer()->get(\App\Repository\GymRepository::class)->findTheOnlyGym();
        static::getContainer()->get(DailyMetricAggregator::class)->backfill($gym);

        $result = $this->request(
            'GET',
            '/reports/attendance?from=' . (new \DateTimeImmutable('-10 days'))->format('Y-m-d')
                . '&to=' . (new \DateTimeImmutable('-6 days'))->format('Y-m-d'),
            $owner,
        );

        $dailyCounts = array_column($result['body']['dailyCounts'], 'count', 'date');
        self::assertSame(1, $dailyCounts[$historicalDay], 'the backfilled historical day should show its real check-in count, not 0');
    }

    // ---- §10.3 Revenue forecasting ------------------------------------------

    public function test_a_brand_new_gym_sees_the_explicit_not_enough_data_state(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/reports/revenue-forecast', $owner);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['body']['hasEnoughData']);
        self::assertSame([], $result['body']['projected']);
    }

    public function test_with_sufficient_snapshot_history_the_forecast_includes_a_projection_and_method(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $gym = static::getContainer()->get(\App\Repository\GymRepository::class)->findTheOnlyGym();
        // GymProvisioningService lazily creates the gym on first Owner action — force it now.
        $this->request('GET', '/reports/dashboard', $owner);
        $gym = static::getContainer()->get(\App\Repository\GymRepository::class)->findTheOnlyGym();

        $snapshotRepo = static::getContainer()->get(\App\Repository\DailyMetricSnapshotRepository::class);
        for ($daysAgo = 20; $daysAgo >= 1; --$daysAgo) {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO daily_metric_snapshot (id, gym_id, snapshot_date, checkins_count, active_members_count, new_members_count, cancelled_members_count, revenue, at_risk_members_count) VALUES (?, ?, ?, 0, 0, 0, 0, ?, 0)',
                [Uuid::v7()->toRfc4122(), (string) $gym->getId(), (new \DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d'), '100.00'],
            );
        }

        $result = $this->request('GET', '/reports/revenue-forecast?days=30', $owner);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['hasEnoughData']);
        self::assertCount(30, $result['body']['projected']);
        self::assertNotNull($result['body']['method']);
        self::assertStringContainsString('Weighted moving average', $result['body']['method']);
    }

    // ---- §10.4 Retention & churn prediction ---------------------------------

    public function test_an_at_risk_member_appears_with_a_specific_reason_not_a_bare_flag(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $enrolled = $this->createPlanAndEnroll($owner, $member, '49.99', 60);
        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET start_date = current_date - interval '40 days' WHERE id = ?",
            [$enrolled['id']],
        );
        $this->request('POST', '/members/me/checkin', $member);
        $checkInId = $this->em->getConnection()->fetchOne('SELECT id FROM attendance_log LIMIT 1');
        $this->em->getConnection()->executeStatement(
            "UPDATE attendance_log SET check_in = current_date - interval '20 days' WHERE id = ?",
            [$checkInId],
        );

        $result = $this->request('GET', '/reports/retention', $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['members']);
        self::assertSame('Mia Member', $result['body']['members'][0]['memberName']);
        self::assertNotEmpty($result['body']['members'][0]['reasons']);
        self::assertStringContainsString('No check-in in 20 days', $result['body']['members'][0]['reasons'][0]);
    }

    public function test_a_member_with_normal_activity_does_not_appear_on_the_retention_list(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);

        $result = $this->request('GET', '/reports/retention', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame([], $result['body']['members']);
    }

    public function test_non_owner_cannot_view_retention_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/reports/retention', $member);

        self::assertSame(403, $result['status']);
    }

    // ---- §10.5 Exportable reports --------------------------------------------

    public function test_owner_can_export_a_csv(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);

        $result = $this->requestRaw('/reports/export?report=attendance&format=csv', $owner);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('text/csv', $result['contentType']);
        self::assertStringContainsString('attachment', $result['contentDisposition']);
        self::assertStringContainsString('Date,Check-ins', $result['body']);
    }

    public function test_owner_can_export_a_pdf(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->requestRaw('/reports/export?report=dashboard&format=pdf', $owner);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('application/pdf', $result['contentType']);
        self::assertStringStartsWith('%PDF', $result['body']);
    }

    public function test_export_rejects_an_invalid_report_or_format(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $badReport = $this->request('GET', '/reports/export?report=nonsense&format=csv', $owner);
        $badFormat = $this->request('GET', '/reports/export?report=attendance&format=xml', $owner);

        self::assertSame(400, $badReport['status']);
        self::assertSame(400, $badFormat['status']);
    }

    /** functional requirements §10.5: export must respect the same gym-scoping as viewing — a Member/Coach can never export another gym's (or any) data. */
    public function test_non_owner_cannot_export_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $memberAttempt = $this->request('GET', '/reports/export?report=dashboard&format=csv', $member);
        $coachAttempt = $this->request('GET', '/reports/export?report=dashboard&format=csv', $coach);

        self::assertSame(403, $memberAttempt['status']);
        self::assertSame(403, $coachAttempt['status']);
    }

    /** ReportVoter's docblock: "exports... can be audit-logged separately per §9's audit log rule." */
    public function test_exporting_creates_an_audit_log_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->requestRaw('/reports/export?report=retention&format=csv', $owner);

        $gymId = static::getContainer()->get(\App\Repository\GymRepository::class)->findTheOnlyGym()->getId();
        $entries = static::getContainer()->get(AuditLogRepository::class)->findForEntity('Gym', $gymId);

        self::assertCount(1, $entries);
        self::assertSame('report.exported', $entries[0]->getAction());
        self::assertSame('retention', $entries[0]->getMetadata()['report']);
        self::assertSame('csv', $entries[0]->getMetadata()['format']);
    }

    private function latestInvoiceId(User $owner): string
    {
        return $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];
    }
}

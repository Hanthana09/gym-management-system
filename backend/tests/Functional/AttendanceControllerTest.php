<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\AttendanceCheckedInEvent;
use App\Event\AttendanceCheckedOutEvent;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers functional requirements §4.1 (self check-in, including all
 * blocked-status reasons). §4.2's Owner-visibility report moved to
 * ReportControllerTest in Phase 11, alongside the /reports/attendance
 * route itself (see ReportController's docblock). The network-failure
 * retry criterion in §4.1 is a client-side concern with nothing
 * server-side to trigger — it's covered by the frontend Playwright
 * verification instead, not here.
 */
final class AttendanceControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(
        string $name,
        string $email,
        UserRole $role = UserRole::MEMBER,
        UserStatus $status = UserStatus::ACTIVE,
    ): User {
        $user = new User($name, $email, null, $role, $status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createApprovedMember(string $name, string $email, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER, $status);
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

    private function createPlanAndEnroll(User $owner, User $member, int $durationDays = 30): void
    {
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Standard',
            'price' => '49.99',
            'durationDays' => $durationDays,
            'features' => [],
        ]);
        $this->request('POST', '/memberships', $owner, [
            'memberUserId' => (string) $member->getId(),
            'planId' => $plan['body']['id'],
        ]);
    }

    // ---- §4.1 Self check-in ------------------------------------------------

    public function test_given_active_membership_when_checkin_then_record_created_with_confirmation(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            AttendanceCheckedInEvent::NAME,
            function (AttendanceCheckedInEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(201, $result['status']);
        self::assertArrayHasKey('checkInAt', $result['body']);
        self::assertSame('manual', $result['body']['method']);
        self::assertCount(1, $dispatched, 'attendance.checked_in should fire for the live counter.');

        $rowCount = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM attendance_log WHERE member_id = ?',
            [(string) $member->getId()],
        );
        self::assertSame('1', (string) $rowCount);
    }

    public function test_given_membership_expired_when_checkin_then_blocked_with_specific_reason(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET end_date = current_date - interval '1 day'",
        );

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('checkin_blocked', $result['body']['error']);
        self::assertSame('membership_expired', $result['body']['reason']);
        self::assertStringContainsString('expired', $result['body']['message']);
    }

    public function test_given_membership_paused_when_checkin_then_blocked_with_specific_reason(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('PATCH', '/members/me/membership/pause', $member);

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('membership_paused', $result['body']['reason']);
        self::assertStringContainsString('paused', $result['body']['message']);
    }

    public function test_given_account_suspended_when_checkin_then_blocked_with_specific_reason(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->em->getConnection()->executeStatement(
            'UPDATE "user" SET status = ? WHERE id = ?',
            ['suspended', (string) $member->getId()],
        );

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('account_suspended', $result['body']['reason']);
        self::assertStringContainsString('suspended', $result['body']['message']);
    }

    public function test_given_cancelled_membership_when_checkin_then_blocked_with_specific_reason(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('PATCH', '/members/me/membership/cancel', $member);

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('membership_cancelled', $result['body']['reason']);
    }

    // ---- gym-management-billing-v1.md §5.5 billing-based check-in gating --

    /** A PENDING invoice before its dueDate does not block — the member is current until the due date actually passes. Confirms the default post-enrollment state is eligible. */
    public function test_given_pending_invoice_before_due_date_when_checkin_then_allowed(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(201, $result['status']);
    }

    /**
     * §5.5 rule 3, evaluated live: a PENDING invoice past dueDate blocks
     * check-in even though it's still formally PENDING (only becomes
     * ABSENT once the next cycle's generation runs) — not dependent on
     * that command having run yet.
     */
    public function test_given_pending_invoice_past_due_date_when_checkin_then_blocked_overdue(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->em->getConnection()->executeStatement(
            "UPDATE invoice SET due_date = current_date - interval '1 day' WHERE membership_id = (SELECT id FROM membership WHERE member_id = ?)",
            [(string) $member->getId()],
        );

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('checkin_blocked', $result['body']['error']);
        self::assertSame('overdue', $result['body']['reason']);
    }

    public function test_given_absent_invoice_on_record_when_checkin_then_blocked_absent_invoice(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->em->getConnection()->executeStatement(
            "UPDATE invoice SET status = 'absent', marked_absent_at = now() WHERE membership_id = (SELECT id FROM membership WHERE member_id = ?)",
            [(string) $member->getId()],
        );

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('absent_invoice', $result['body']['reason']);
    }

    public function test_given_suspended_subscription_when_checkin_then_blocked_subscription_inactive(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $membershipId = $this->request('GET', '/members/me/membership', $member)['body']['membership']['id'];
        $this->request('PATCH', "/memberships/{$membershipId}/suspend", $owner);

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('subscription_inactive', $result['body']['reason']);
    }

    public function test_given_no_membership_when_checkin_then_blocked_with_specific_reason(): void
    {
        // A gym (and its primary branch) always exists by the time a real
        // Member account exists — they're necessarily invited by an Owner,
        // which lazily provisions both. createApprovedMember() bypasses
        // that flow (constructs User+MemberProfile directly), so this test
        // provisions the same context explicitly rather than relying on it.
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $gym = new Gym("Olivia's Gym", '', $owner);
        $this->em->persist($gym);
        $this->em->persist(new Branch($gym, "Olivia's Gym", '', isPrimary: true));
        $this->em->flush();

        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('no_membership', $result['body']['reason']);
    }

    // ---- Check-in-timer feature: check-out mutation ------------------------

    public function test_given_open_session_when_member_checks_out_then_check_out_time_recorded(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            AttendanceCheckedOutEvent::NAME,
            function (AttendanceCheckedOutEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('POST', '/members/me/checkout', $member);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('checkInAt', $result['body']);
        self::assertNotNull($result['body']['checkOutAt']);
        self::assertCount(1, $dispatched, 'attendance.checked_out should fire so the top-bar timer syncs via Mercure.');
        self::assertSame((string) $member->getId(), (string) $dispatched[0]->getLog()->getMember()->getUser()->getId());
    }

    public function test_given_no_open_session_when_member_checks_out_then_409(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('POST', '/members/me/checkout', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('no_active_session', $result['body']['error']);
    }

    /**
     * The "checkout triggered from another device" scenario the top-bar
     * timer's Mercure sync exists for: the checkout call itself carries no
     * concept of "which browser tab" — it's a second, independent request
     * against the same member, exactly what a second device/tab would
     * send. AttendanceMercurePublisherTest separately proves the Mercure
     * payload shape this event produces; this proves the full HTTP path
     * dispatches that event with the correct, freshly-closed log.
     */
    public function test_checkout_from_a_second_session_dispatches_the_event_the_other_tabs_timer_syncs_from(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $checkIn = $this->request('POST', '/members/me/checkin', $member);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            AttendanceCheckedOutEvent::NAME,
            function (AttendanceCheckedOutEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        // A second, independent request with a freshly-issued token for
        // the same member — WebTestCase only supports one KernelBrowser
        // per test, but a fresh token is what actually distinguishes "a
        // separate device/session" here (the original tab's own request
        // that produced $checkIn above is long since complete), so this
        // still exercises "checkout arrives from somewhere other than the
        // tab currently displaying the timer."
        $result = $this->request('POST', '/members/me/checkout', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $dispatched);
        $log = $dispatched[0]->getLog();
        self::assertSame($checkIn['body']['checkInAt'], $log->getCheckIn()->format(\DateTimeInterface::ATOM));
        self::assertNotNull($log->getCheckOut(), "the original tab's timer freezes off this value");
    }

    // ---- Check-in-timer feature: GET /members/:id/attendance/active -------

    public function test_given_open_session_when_active_attendance_fetched_then_returned(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);

        $result = $this->request('GET', "/members/{$member->getId()}/attendance/active", $member);

        self::assertSame(200, $result['status']);
        self::assertNotNull($result['body']['attendance']);
        self::assertArrayHasKey('checkInAt', $result['body']['attendance']);
        self::assertNull($result['body']['attendance']['checkOutAt']);
    }

    public function test_given_no_open_session_when_active_attendance_fetched_then_null(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);

        $result = $this->request('GET', "/members/{$member->getId()}/attendance/active", $member);

        self::assertSame(200, $result['status']);
        self::assertNull($result['body']['attendance']);
    }

    /** The 403 the top-bar's GET-on-mount request must respect: a Member cannot read another member's active-session data. */
    public function test_a_different_member_cannot_fetch_someone_elses_active_attendance_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);
        $someoneElse = $this->createApprovedMember('Sam Someone', 'sam@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}/attendance/active", $someoneElse);

        self::assertSame(403, $result['status']);
    }

    public function test_owner_can_fetch_any_members_active_attendance(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $member);
        $this->request('POST', '/members/me/checkin', $member);

        $result = $this->request('GET', "/members/{$member->getId()}/attendance/active", $owner);

        self::assertSame(200, $result['status']);
        self::assertNotNull($result['body']['attendance']);
    }
}

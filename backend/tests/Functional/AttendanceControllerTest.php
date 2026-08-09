<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\AttendanceCheckedInEvent;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers functional requirements §4.1 (self check-in, including all
 * blocked-status reasons) and §4.2 (Owner visibility: live counter event
 * + date-range report). The network-failure retry criterion in §4.1 is a
 * client-side concern with nothing server-side to trigger — it's covered
 * by the frontend Playwright verification instead, not here.
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

    public function test_given_no_membership_when_checkin_then_blocked_with_specific_reason(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('no_membership', $result['body']['reason']);
    }

    // ---- §4.2 Owner visibility ----------------------------------------------

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
}

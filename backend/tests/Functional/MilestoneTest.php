<?php

namespace App\Tests\Functional;

use App\Attendance\StreakCalculator;
use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\MemberMilestoneReachedEvent;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers roadmap Phase 9.3 (GTM Pillar D): streak detection (StreakCalculator,
 * tested directly the same way MembershipExpiryScannerTest tests its
 * scanner service) and the full event → notification chain, proving the
 * Notification module (Phase 7) needed no changes to pick up a brand-new
 * event type.
 */
final class MilestoneTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE notification, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(string $name, string $email, UserRole $role = UserRole::MEMBER, UserStatus $status = UserStatus::ACTIVE): User
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

    private function createPlanAndEnroll(User $owner, User $member): void
    {
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Standard', 'price' => '49.99', 'durationDays' => 30, 'features' => []]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);
    }

    private function backdateCheckIn(MemberProfile $member, int $daysAgo): void
    {
        $log = new AttendanceLog($member, $this->primaryBranch(), new \DateTimeImmutable("-{$daysAgo} days"), CheckInMethod::MANUAL);
        $this->em->persist($log);
        $this->em->flush();
    }

    /**
     * Some tests here only exercise StreakCalculator directly (no HTTP
     * request, so no gym is ever lazily provisioned); others go through
     * createPlanAndEnroll() first, whose HTTP request runs against a
     * different (rebooted-kernel) EntityManager than $this->em. Always
     * re-fetching (or creating) through $this->em specifically — never
     * mixing in static::getContainer()'s repositories — is what avoids
     * Doctrine's "new entity found through relationship, not configured
     * to cascade persist" error when persisting the AttendanceLog below.
     */
    private function primaryBranch(): Branch
    {
        $gym = $this->em->getRepository(Gym::class)->findOneBy([]);
        if ($gym === null) {
            $owner = $this->createUser('Test Owner', 'test-owner-' . bin2hex(random_bytes(4)) . '@example.com', UserRole::OWNER);
            $gym = new Gym('Test Gym', '', $owner);
            $this->em->persist($gym);
        }

        $branch = $this->em->getRepository(Branch::class)->findOneBy(['gym' => $gym, 'isPrimary' => true]);
        if ($branch === null) {
            $branch = new Branch($gym, 'Main', '', isPrimary: true);
            $this->em->persist($branch);
        }
        $this->em->flush();

        return $branch;
    }

    private function memberProfile(User $user): MemberProfile
    {
        return $this->em->getRepository(MemberProfile::class)->find($user->getId());
    }

    // ---- StreakCalculator correctness ------------------------------------

    public function test_streak_counts_consecutive_days_ending_today(): void
    {
        $member = $this->memberProfile($this->createApprovedMember('Mia Member', 'mia@example.com'));
        $this->backdateCheckIn($member, 2);
        $this->backdateCheckIn($member, 1);
        $this->backdateCheckIn($member, 0);

        $streak = static::getContainer()->get(StreakCalculator::class)->currentStreakDays($member, new \DateTimeImmutable('today'));

        self::assertSame(3, $streak);
    }

    public function test_streak_resets_after_a_gap(): void
    {
        $member = $this->memberProfile($this->createApprovedMember('Mia Member', 'mia@example.com'));
        $this->backdateCheckIn($member, 5); // the gap
        $this->backdateCheckIn($member, 1);
        $this->backdateCheckIn($member, 0);

        $streak = static::getContainer()->get(StreakCalculator::class)->currentStreakDays($member, new \DateTimeImmutable('today'));

        self::assertSame(2, $streak);
    }

    public function test_multiple_checkins_on_the_same_day_count_once(): void
    {
        $member = $this->memberProfile($this->createApprovedMember('Mia Member', 'mia@example.com'));
        $this->backdateCheckIn($member, 0);
        $this->backdateCheckIn($member, 0);
        $this->backdateCheckIn($member, 0);

        $streak = static::getContainer()->get(StreakCalculator::class)->currentStreakDays($member, new \DateTimeImmutable('today'));

        self::assertSame(1, $streak);
    }

    public function test_streak_is_zero_when_today_has_no_checkin(): void
    {
        $member = $this->memberProfile($this->createApprovedMember('Mia Member', 'mia@example.com'));
        $this->backdateCheckIn($member, 2);
        $this->backdateCheckIn($member, 1);

        $streak = static::getContainer()->get(StreakCalculator::class)->currentStreakDays($member, new \DateTimeImmutable('today'));

        self::assertSame(0, $streak);
    }

    // ---- End-to-end: crossing a threshold -> event -> notification -------

    /**
     * The core claim of this phase: a brand-new event flows into a
     * Notification row purely because MilestoneNotificationSubscriber
     * calls the existing NotificationService — nothing in Phase 7's own
     * files changes for this to work.
     */
    public function test_reaching_the_3_day_threshold_dispatches_milestone_event_and_creates_a_notification(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $memberUser = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $memberUser);
        $memberProfile = $this->memberProfile($memberUser);
        $this->backdateCheckIn($memberProfile, 2);
        $this->backdateCheckIn($memberProfile, 1);
        // Today's check-in (below) brings the streak to exactly 3.

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            MemberMilestoneReachedEvent::NAME,
            function (MemberMilestoneReachedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $checkIn = $this->request('POST', '/members/me/checkin', $memberUser);
        self::assertSame(201, $checkIn['status']);

        self::assertCount(1, $dispatched, 'member.milestone_reached should fire exactly once.');
        self::assertSame('checkin_streak', $dispatched[0]->getMilestoneType());
        self::assertSame(3, $dispatched[0]->getValue());

        $notifications = $this->request('GET', '/notifications', $memberUser);
        self::assertCount(1, $notifications['body']['notifications']);
        self::assertSame('Milestone reached!', $notifications['body']['notifications'][0]['title']);
        self::assertSame('system', $notifications['body']['notifications'][0]['type']);
        self::assertNull($notifications['body']['notifications'][0]['sourceRole']);
        self::assertStringContainsString('3-day', $notifications['body']['notifications'][0]['body']);
    }

    public function test_a_streak_day_that_is_not_a_threshold_does_not_dispatch_a_milestone(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $memberUser = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->createPlanAndEnroll($owner, $memberUser);
        $memberProfile = $this->memberProfile($memberUser);
        $this->backdateCheckIn($memberProfile, 1);
        // Today's check-in brings the streak to 2 — not in the threshold set.

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            MemberMilestoneReachedEvent::NAME,
            function (MemberMilestoneReachedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $this->request('POST', '/members/me/checkin', $memberUser);

        self::assertCount(0, $dispatched);

        $notifications = $this->request('GET', '/notifications', $memberUser);
        self::assertCount(0, $notifications['body']['notifications']);
    }
}

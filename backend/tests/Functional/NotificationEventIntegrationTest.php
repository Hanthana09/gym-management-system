<?php

namespace App\Tests\Functional;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Membership\MembershipExpiryScanner;
use App\Notification\SendNotificationEmailMessage;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Proves the actual point of this phase: existing Phases 2–6 events
 * (already emitted, unmodified here) turn into Notification rows purely
 * via new listeners — this is the "invitation.sent, invitation.approved,
 * session.requested, session.confirmed, membership.expiring, etc." list
 * from the task, exercised end to end through the real HTTP/service
 * layer rather than by calling NotificationService directly.
 */
final class NotificationEventIntegrationTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE notification, announcement, pt_session, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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

    private function notificationsFor(User $user): array
    {
        return $this->request('GET', '/notifications', $user)['body']['notifications'];
    }

    // ---- Invitation events (architecture doc §6.7) ---------------------

    public function test_invitation_approved_notifies_the_owner(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $invited = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        // Owner must actually send the invite (provisions the gym + Invitation row).
        $this->request('POST', '/invitations', $owner, ['destination' => 'coach@example.com', 'role' => 'coach']);
        $mine = $this->request('GET', '/invitations/me', $invited);
        $invitationId = $mine['body']['invitations'][0]['id'];

        $this->request('PATCH', "/invitations/{$invitationId}/approve", $invited);

        $notifications = $this->notificationsFor($owner);
        self::assertCount(1, $notifications);
        self::assertSame('system', $notifications[0]['type']);
        self::assertSame('coach', $notifications[0]['sourceRole']);
        self::assertStringContainsString('approved', $notifications[0]['title']);
    }

    public function test_invitation_declined_notifies_the_owner(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $invited = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $this->request('POST', '/invitations', $owner, ['destination' => 'mia@example.com', 'role' => 'member']);
        $mine = $this->request('GET', '/invitations/me', $invited);
        $invitationId = $mine['body']['invitations'][0]['id'];

        $this->request('PATCH', "/invitations/{$invitationId}/decline", $invited);

        $notifications = $this->notificationsFor($owner);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('declined', $notifications[0]['title']);
    }

    // ---- PT session events (architecture doc §8.2 / functional requirements §5.1-5.2) ----

    /** roadmap Phase 16: PtSessionVoter::RESPOND now requires the Coach be assigned to the session's branch — every coach this test creates is assigned to the (single, primary) branch, matching the single-branch regression case. */
    private function createCoach(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::COACH);
        $this->em->persist(new CoachProfile($user));
        $this->em->persist(new \App\Entity\BranchAssignment($user, $this->primaryBranch()));
        $this->em->flush();

        return $user;
    }

    private function primaryBranch(): \App\Entity\Branch
    {
        $gym = $this->em->getRepository(\App\Entity\Gym::class)->findOneBy([]);
        if ($gym === null) {
            $owner = $this->createUser('Olivia Owner', 'owner-' . bin2hex(random_bytes(4)) . '@example.com', UserRole::OWNER);
            $gym = new \App\Entity\Gym("Olivia's Gym", '', $owner);
            $this->em->persist($gym);
        }

        $branch = $this->em->getRepository(\App\Entity\Branch::class)->findOneBy(['gym' => $gym, 'isPrimary' => true]);
        if ($branch === null) {
            $branch = new \App\Entity\Branch($gym, 'Main', '', isPrimary: true);
            $this->em->persist($branch);
        }
        $this->em->flush();

        return $branch;
    }

    private function createMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER);
        $this->em->persist(new MemberProfile($user));
        $this->em->flush();

        return $user;
    }

    public function test_session_requested_notifies_the_coach(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');

        $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => 60,
        ]);

        $notifications = $this->notificationsFor($coach);
        self::assertCount(1, $notifications);
        self::assertSame('booking', $notifications[0]['type']);
        self::assertSame('member', $notifications[0]['sourceRole']);
    }

    public function test_session_confirmed_notifies_the_member(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => 60,
        ]);

        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);

        $notifications = $this->notificationsFor($member);
        self::assertCount(1, $notifications);
        self::assertSame('booking', $notifications[0]['type']);
        self::assertSame('coach', $notifications[0]['sourceRole']);
        self::assertStringContainsString('confirmed', $notifications[0]['title']);
    }

    /** functional requirements §5.2: "Given a pending request, when I decline, then the Member is notified." */
    public function test_session_declined_notifies_the_member(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => 60,
        ]);

        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'declined']);

        $notifications = $this->notificationsFor($member);
        self::assertCount(1, $notifications);
        self::assertStringContainsString('declined', $notifications[0]['title']);
    }

    // ---- Membership expiry (architecture doc §8.3) ----------------------

    public function test_membership_expiring_notifies_the_member(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Gold', 'price' => '79.99', 'durationDays' => 30, 'features' => []]);
        $enrolled = $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);
        // Back-date so the scanner's "expires in 7 days" threshold matches today.
        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET end_date = current_date + interval '7 day' WHERE id = ?",
            [$enrolled['body']['id']],
        );

        static::getContainer()->get(MembershipExpiryScanner::class)->scan();

        $notifications = $this->notificationsFor($member);
        self::assertCount(1, $notifications);
        self::assertSame('billing', $notifications[0]['type']);
        self::assertNull($notifications[0]['sourceRole']); // no human actor for a scheduled reminder
        self::assertStringContainsString('7 day', $notifications[0]['body']);
    }

    // ---- Async email delivery (architecture doc §6.6) -------------------

    /**
     * "A slow email provider must not block the request that triggered
     * it" — proven here by asserting the email send is a *queued message*
     * on the async transport, not something that happened synchronously
     * inside the HTTP request. In test env this transport is in-memory
     * (config/packages/messenger.yaml's when@test block) rather than the
     * real Doctrine-backed queue used in dev, so no actual consumer is
     * needed to make this assertion.
     */
    public function test_notification_creation_queues_an_async_email_message(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');

        $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => 60,
        ]);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $emailMessages = array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof SendNotificationEmailMessage,
        );

        self::assertCount(1, $emailMessages);
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\Notification;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Notification\NotificationService;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers functional requirements §6.1 (receiving notifications, read
 * state persisting across sessions) and gym-management-dashboard-
 * redesign.md Phase 0: the final `/api/v1/notifications` path scheme —
 * `GET` (list, API Platform), `PATCH /{id}` (mark one read, hand-
 * written), `POST /mark-all-read` (hand-written), `GET /unread-count`
 * (hand-written) — with an explicit regression test hitting all four to
 * confirm no routing collision remains (the project's known `/api/api/`
 * double-prefix pitfall, plus API Platform's auto-added implicit item
 * Get operation swallowing `/unread-count` as `{id}` — both fixed as
 * part of this phase, see NotificationController's own docblock).
 */
final class NotificationControllerTest extends WebTestCase
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

    private function createUser(string $name, string $email, UserRole $role = UserRole::MEMBER): User
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

    private function request(string $method, string $uri, ?User $actingAs, array $data = []): array
    {
        // HTTP_ACCEPT matches what the frontend's authFetch now sends for
        // the list endpoint (API Platform GetCollection) — without it,
        // jsonld's {member, totalItems} envelope (kept as the default so
        // Exercise's own API Platform consumption is unaffected) would be
        // returned instead of the flat array these tests assert against.
        // Harmless on the hand-written routes below, which ignore Accept
        // entirely.
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json'];
        if ($actingAs !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->accessTokenFor($actingAs);
        }

        $this->client->request(
            $method,
            '/api/v1' . $uri,
            server: $server,
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function notify(User $user, string $title = 'Title', string $body = 'Body'): Notification
    {
        return static::getContainer()->get(NotificationService::class)->notify($user, NotificationType::SYSTEM, $title, $body);
    }

    // ---- Phase 0 stop condition: one final path scheme, no collisions -----

    /**
     * Regression test for both known pitfalls: the `/api/api/` double
     * prefix (config/routes/api_platform.yaml vs config/packages/
     * api_platform.yaml) and API Platform's auto-added implicit item Get
     * operation matching `/unread-count` as `{id}` — hits all four final
     * paths in one place and asserts each lands on the correct handler,
     * not a 404/collision.
     */
    public function test_all_four_final_notification_paths_resolve_with_no_collision(): void
    {
        $user = $this->createUser('Nora Notif', 'nora@example.com');
        $notification = $this->notify($user);

        $list = $this->request('GET', '/notifications', $user);
        self::assertSame(200, $list['status']);
        self::assertIsArray($list['body']);
        self::assertArrayNotHasKey('notifications', $list['body'], 'List must be a flat array, not the old {notifications, unreadCount} envelope.');
        self::assertCount(1, $list['body']);

        $unreadCount = $this->request('GET', '/notifications/unread-count', $user);
        self::assertSame(200, $unreadCount['status']);
        self::assertSame(1, $unreadCount['body']['unreadCount']);

        $markRead = $this->request('PATCH', '/notifications/' . $notification->getId(), $user);
        self::assertSame(200, $markRead['status']);
        self::assertTrue($markRead['body']['read']);

        $markAllRead = $this->request('POST', '/notifications/mark-all-read', $user);
        self::assertSame(200, $markAllRead['status']);

        $unreadCountAfter = $this->request('GET', '/notifications/unread-count', $user);
        self::assertSame(0, $unreadCountAfter['body']['unreadCount']);
    }

    public function test_unauthenticated_requests_to_every_notification_path_are_401(): void
    {
        $user = $this->createUser('Nora Notif', 'nora401@example.com');
        $notification = $this->notify($user);

        self::assertSame(401, $this->request('GET', '/notifications', null)['status']);
        self::assertSame(401, $this->request('GET', '/notifications/unread-count', null)['status']);
        self::assertSame(401, $this->request('PATCH', '/notifications/' . $notification->getId(), null)['status']);
        self::assertSame(401, $this->request('POST', '/notifications/mark-all-read', null)['status']);
    }

    // ---- §6.1 Receiving notifications ---------------------------------

    public function test_given_notifications_exist_when_listed_then_returns_own_newest_first(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $this->notify($member, 'First');
        $this->notify($member, 'Second');

        $result = $this->request('GET', '/notifications', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']);
        self::assertSame('Second', $result['body'][0]['title']);
    }

    public function test_a_users_notifications_never_include_someone_elses(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $someoneElse = $this->createUser('Someone Else', 'else@example.com');
        $this->notify($someoneElse, 'Not for Mia');

        $result = $this->request('GET', '/notifications', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']);
    }

    /** functional requirements §6.1: "Given I mark a notification read, when I do, then it's reflected immediately and persists across sessions/devices." */
    public function test_given_mark_read_when_completed_then_read_true_and_persists_on_a_fresh_fetch(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $notification = $this->notify($member);

        $marked = $this->request('PATCH', '/notifications/' . $notification->getId(), $member);
        self::assertSame(200, $marked['status']);
        self::assertTrue($marked['body']['read']);

        // A brand-new access token simulates "a different session/device" —
        // the read state must come from the database, not request-local state.
        $refetched = $this->request('GET', '/notifications', $member);
        self::assertTrue($refetched['body'][0]['read']);

        $unreadCount = $this->request('GET', '/notifications/unread-count', $member);
        self::assertSame(0, $unreadCount['body']['unreadCount']);
    }

    public function test_a_different_user_cannot_mark_someone_elses_notification_read_403(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $someoneElse = $this->createUser('Someone Else', 'else@example.com');
        $notification = $this->notify($member);

        $result = $this->request('PATCH', '/notifications/' . $notification->getId(), $someoneElse);

        self::assertSame(403, $result['status']);
    }

    // ---- mark-all-read ---------------------------------------------------

    public function test_mark_all_read_marks_every_unread_notification_but_never_another_users(): void
    {
        $member = $this->createUser('Mia Member', 'mia-mar@example.com');
        $someoneElse = $this->createUser('Someone Else', 'else-mar@example.com');
        $this->notify($member, 'One');
        $this->notify($member, 'Two');
        $this->notify($someoneElse, 'Not mine');

        $result = $this->request('POST', '/notifications/mark-all-read', $member);
        self::assertSame(200, $result['status']);

        $mine = $this->request('GET', '/notifications', $member);
        self::assertTrue($mine['body'][0]['read']);
        self::assertTrue($mine['body'][1]['read']);

        $othersCount = $this->request('GET', '/notifications/unread-count', $someoneElse);
        self::assertSame(1, $othersCount['body']['unreadCount'], "mark-all-read must never touch another user's notifications.");
    }
}

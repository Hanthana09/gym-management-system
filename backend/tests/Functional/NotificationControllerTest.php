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
 * Covers functional requirements §6.1: receiving notifications (history,
 * read state persisting across sessions) and the NotificationVoter's
 * enforcement at the HTTP layer (companion to the unit test).
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

    private function notify(User $user, string $title = 'Title', string $body = 'Body'): Notification
    {
        return static::getContainer()->get(NotificationService::class)->notify($user, NotificationType::SYSTEM, $title, $body);
    }

    // ---- §6.1 Receiving notifications ---------------------------------

    public function test_given_notifications_exist_when_listed_then_returns_own_newest_first(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $this->notify($member, 'First');
        $this->notify($member, 'Second');

        $result = $this->request('GET', '/notifications', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['notifications']);
        self::assertSame('Second', $result['body']['notifications'][0]['title']);
        self::assertSame(2, $result['body']['unreadCount']);
    }

    public function test_a_users_notifications_never_include_someone_elses(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $someoneElse = $this->createUser('Someone Else', 'else@example.com');
        $this->notify($someoneElse, 'Not for Mia');

        $result = $this->request('GET', '/notifications', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['notifications']);
    }

    /** functional requirements §6.1: "Given I mark a notification read, when I do, then it's reflected immediately and persists across sessions/devices." */
    public function test_given_mark_read_when_completed_then_read_true_and_persists_on_a_fresh_fetch(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $notification = $this->notify($member);

        $marked = $this->request('PATCH', '/notifications/' . $notification->getId() . '/read', $member);
        self::assertSame(200, $marked['status']);
        self::assertTrue($marked['body']['read']);

        // A brand-new access token simulates "a different session/device" —
        // the read state must come from the database, not request-local state.
        $refetched = $this->request('GET', '/notifications', $member);
        self::assertTrue($refetched['body']['notifications'][0]['read']);
        self::assertSame(0, $refetched['body']['unreadCount']);
    }

    public function test_a_different_user_cannot_mark_someone_elses_notification_read_403(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com');
        $someoneElse = $this->createUser('Someone Else', 'else@example.com');
        $notification = $this->notify($member);

        $result = $this->request('PATCH', '/notifications/' . $notification->getId() . '/read', $someoneElse);

        self::assertSame(403, $result['status']);
    }
}

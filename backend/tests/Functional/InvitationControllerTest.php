<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\InvitationApprovedEvent;
use App\Event\InvitationDeclinedEvent;
use App\Event\InvitationSentEvent;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers functional requirements §2.1 (Owner sends an invitation) and
 * §2.2 (invitee approves or declines), including duplicate-invite,
 * expiry, and the 403 case.
 */
final class InvitationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(
        string $name,
        ?string $email,
        ?string $phone,
        UserRole $role = UserRole::MEMBER,
        UserStatus $status = UserStatus::ACTIVE,
    ): User {
        $user = new User($name, $email, $phone, $role, $status);
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
            'body' => json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    // ---- §2.1 Owner sends an invitation ----------------------------------

    public function test_given_email_and_role_when_invitation_created_then_pending_and_events_dispatched(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000001', UserRole::OWNER);

        // Verifying the event actually fires (not just trusting the code)
        // needs the listener attached to the same container the request
        // runs in — disable the client's default per-request kernel reboot
        // for this one test.
        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            InvitationSentEvent::NAME,
            function (InvitationSentEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('POST', '/invitations', $owner, ['destination' => 'invitee@example.com', 'role' => 'member']);

        self::assertSame(201, $result['status']);
        self::assertSame('pending', $result['body']['status']);
        self::assertSame('invitee@example.com', $result['body']['destination']);
        self::assertSame('member', $result['body']['role']);
        self::assertCount(1, $dispatched, 'invitation.sent should have been dispatched exactly once.');
    }

    public function test_given_pending_invite_reinvited_when_tried_again_then_returns_existing_not_duplicate(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000002', UserRole::OWNER);

        $first = $this->request('POST', '/invitations', $owner, ['destination' => 'dup@example.com', 'role' => 'coach']);
        $second = $this->request('POST', '/invitations', $owner, ['destination' => 'dup@example.com', 'role' => 'coach']);

        self::assertSame(201, $first['status']);
        self::assertSame(200, $second['status'], 'Re-inviting a pending destination should not be a fresh 201 create.');
        self::assertSame($first['body']['id'], $second['body']['id']);

        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invitation WHERE email = ?', ['dup@example.com']);
        self::assertSame('1', (string) $count);
    }

    public function test_given_invitation_not_responded_in_7_days_when_expiry_passes_then_expired_and_owner_can_reinvite(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000003', UserRole::OWNER);
        $created = $this->request('POST', '/invitations', $owner, ['destination' => 'stale@example.com', 'role' => 'member']);

        $this->em->getConnection()->executeStatement(
            "UPDATE invitation SET expires_at = now() - interval '1 minute' WHERE id = ?",
            [$created['body']['id']],
        );

        // Sending again after expiry must create a NEW invitation, not reuse the stale one.
        $reinvited = $this->request('POST', '/invitations', $owner, ['destination' => 'stale@example.com', 'role' => 'member']);

        self::assertSame(201, $reinvited['status']);
        self::assertNotSame($created['body']['id'], $reinvited['body']['id']);

        $staleStatus = $this->em->getConnection()->fetchOne('SELECT status FROM invitation WHERE id = ?', [$created['body']['id']]);
        self::assertSame('expired', $staleStatus);
    }

    public function test_non_owner_cannot_send_invitation_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', '+15550000004', UserRole::COACH);

        $result = $this->request('POST', '/invitations', $coach, ['destination' => 'someone@example.com', 'role' => 'member']);

        self::assertSame(403, $result['status']);
    }

    // ---- §2.2 Invitee approves or declines --------------------------------

    public function test_given_pending_invitation_when_invitee_checks_then_visible_via_invitations_me(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000005', UserRole::OWNER);
        $invitee = $this->createUser('Mia Member', 'mia@example.com', '+15550000006', UserRole::MEMBER, UserStatus::PENDING_APPROVAL);
        $this->request('POST', '/invitations', $owner, ['destination' => 'mia@example.com', 'role' => 'member']);

        $result = $this->request('GET', '/invitations/me', $invitee);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['invitations']);
        self::assertSame('pending', $result['body']['invitations'][0]['status']);
    }

    public function test_given_approve_when_completed_then_user_active_profile_created_and_event_dispatched(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000007', UserRole::OWNER);
        $invitee = $this->createUser('Carlos Coach', 'carlos@example.com', '+15550000008', UserRole::COACH, UserStatus::PENDING_APPROVAL);
        $created = $this->request('POST', '/invitations', $owner, ['destination' => 'carlos@example.com', 'role' => 'coach']);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            InvitationApprovedEvent::NAME,
            function (InvitationApprovedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('PATCH', "/invitations/{$created['body']['id']}/approve", $invitee);

        self::assertSame(200, $result['status']);
        self::assertSame('approved', $result['body']['status']);
        self::assertCount(1, $dispatched);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM "user" WHERE id = ?', [(string) $invitee->getId()]);
        self::assertSame('active', $status);

        $profileCount = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM coach_profile WHERE user_id = ?', [(string) $invitee->getId()]);
        self::assertSame('1', (string) $profileCount);
    }

    public function test_given_decline_when_completed_then_no_profile_created_invitation_closed_and_event_dispatched(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000009', UserRole::OWNER);
        $invitee = $this->createUser('Mia Member', 'mia2@example.com', '+15550000010', UserRole::MEMBER, UserStatus::PENDING_APPROVAL);
        $created = $this->request('POST', '/invitations', $owner, ['destination' => 'mia2@example.com', 'role' => 'member']);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            InvitationDeclinedEvent::NAME,
            function (InvitationDeclinedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('PATCH', "/invitations/{$created['body']['id']}/decline", $invitee);

        self::assertSame(200, $result['status']);
        self::assertSame('declined', $result['body']['status']);
        self::assertCount(1, $dispatched);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM "user" WHERE id = ?', [(string) $invitee->getId()]);
        self::assertSame('pending_approval', $status, 'Declining must not activate the account.');

        $profileCount = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM member_profile WHERE user_id = ?', [(string) $invitee->getId()]);
        self::assertSame('0', (string) $profileCount);
    }

    /**
     * functional requirements §2.2: "this must hold even if I somehow have
     * the invitation's ID." HTTP-level companion to the Voter unit test.
     */
    public function test_given_different_user_tries_to_approve_when_attempted_then_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000011', UserRole::OWNER);
        $actualInvitee = $this->createUser('Mia Member', 'mia3@example.com', '+15550000012', UserRole::MEMBER, UserStatus::PENDING_APPROVAL);
        $someoneElse = $this->createUser('Someone Else', 'else@example.com', '+15550000013', UserRole::MEMBER);
        $created = $this->request('POST', '/invitations', $owner, ['destination' => 'mia3@example.com', 'role' => 'member']);

        $result = $this->request('PATCH', "/invitations/{$created['body']['id']}/approve", $someoneElse);

        self::assertSame(403, $result['status']);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM invitation WHERE id = ?', [$created['body']['id']]);
        self::assertSame('pending', $status, 'The invitation must remain untouched.');
    }

    public function test_given_already_responded_invitation_when_responded_to_again_then_conflict(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000014', UserRole::OWNER);
        $invitee = $this->createUser('Mia Member', 'mia4@example.com', '+15550000015', UserRole::MEMBER, UserStatus::PENDING_APPROVAL);
        $created = $this->request('POST', '/invitations', $owner, ['destination' => 'mia4@example.com', 'role' => 'member']);
        $this->request('PATCH', "/invitations/{$created['body']['id']}/approve", $invitee);

        $result = $this->request('PATCH', "/invitations/{$created['body']['id']}/decline", $invitee);

        self::assertSame(409, $result['status']);
        self::assertSame('invitation_already_responded', $result['body']['error']);
    }

    // ---- OTP <-> Invitation integration (architecture doc §6.7) ----------

    public function test_given_no_existing_account_when_otp_verified_for_invited_destination_then_account_created(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', '+15550000016', UserRole::OWNER);
        $this->request('POST', '/invitations', $owner, ['destination' => 'brandnew@example.com', 'role' => 'coach']);

        $this->client->request(
            'POST',
            '/auth/otp/request',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'],
            content: json_encode(['destination' => 'brandnew@example.com']),
        );
        $email = $this->getMailerMessage(0);
        self::assertNotNull($email, 'A code should be sent for a destination with a pending invitation.');
        preg_match('/\b(\d{6})\b/', $email->getTextBody(), $matches);

        $this->client->request(
            'POST',
            '/auth/otp/verify',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'],
            content: json_encode(['destination' => 'brandnew@example.com', 'code' => $matches[1]]),
        );
        $verifyBody = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('coach', $verifyBody['user']['role']);

        $userStatus = $this->em->getConnection()->fetchOne('SELECT status FROM "user" WHERE email = ?', ['brandnew@example.com']);
        self::assertSame('pending_approval', $userStatus);
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\PasswordReset\SendPasswordResetCodeMessage;
use App\PasswordReset\SendPasswordResetCodeMessageHandler;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers gym-management-password-auth.md: Owner-assigned passwords (§3.1)
 * and self-service forgot/reset password (§3.2), including the
 * negative/403 cases in §6/§8.
 */
final class PasswordAuthControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE password_reset_token, otp_code, refresh_token, audit_log, gym, "user" CASCADE',
        );
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(
        string $email,
        ?string $phone = null,
        ?string $password = null,
        UserRole $role = UserRole::MEMBER,
        bool $requiresPasswordChange = false,
    ): User {
        $user = new User('Test User', $email, $phone, $role, UserStatus::ACTIVE);

        if ($password !== null) {
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
            $user->setPasswordHash($hasher->hashPassword($user, $password));
            $user->setRequiresPasswordChange($requiresPasswordChange);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function postJson(string $uri, array $data, ?string $bearerToken = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'];
        if ($bearerToken !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
        }

        $this->client->request('POST', $uri, server: $server, content: json_encode($data, \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    /**
     * Requests a reset code via the real endpoint, runs the queued
     * Messenger handler (simulating a `messenger:consume async` worker,
     * same pattern WhatsAppNotificationTest uses), and extracts the raw
     * token from the captured email.
     */
    private function requestResetAndCaptureToken(string $identifier): string
    {
        $this->postJson('/api/auth/forgot-password', ['identifier' => $identifier]);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $envelope = current(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof SendPasswordResetCodeMessage,
        ));
        self::assertNotFalse($envelope, 'Expected a SendPasswordResetCodeMessage to have been queued.');

        static::getContainer()->get(SendPasswordResetCodeMessageHandler::class)($envelope->getMessage());

        $messages = $this->getMailerMessages();
        $email = end($messages);
        self::assertNotFalse($email, 'Expected a password reset email to have been sent.');
        preg_match('/code is ([0-9A-Za-z]{32})\./', $email->getTextBody(), $matches);
        self::assertNotEmpty($matches, 'Expected a 32-char token in the email body.');

        return $matches[1];
    }

    // ---- §3.1 Owner-assigned passwords -------------------------------------

    public function test_owner_can_set_a_generated_password_for_a_member_and_it_forces_change_on_login(): void
    {
        $owner = $this->createUser('owner1@example.com', role: UserRole::OWNER);
        $member = $this->createUser('member1@example.com');

        $result = $this->postJson("/api/users/{$member->getId()}/set-password", [], $this->accessTokenFor($owner));

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('password', $result['body']);
        self::assertSame(10, strlen($result['body']['password']));

        $login = $this->postJson('/api/auth/login', ['email' => 'member1@example.com', 'password' => $result['body']['password']]);

        self::assertSame(200, $login['status']);
        self::assertTrue($login['body']['password_change_required']);
    }

    public function test_owner_can_set_an_explicit_password_for_a_coach(): void
    {
        $owner = $this->createUser('owner2@example.com', role: UserRole::OWNER);
        $coach = $this->createUser('coach1@example.com', role: UserRole::COACH);

        $result = $this->postJson("/api/users/{$coach->getId()}/set-password", ['password' => 'a-chosen-password'], $this->accessTokenFor($owner));

        self::assertSame(200, $result['status']);
        self::assertSame('a-chosen-password', $result['body']['password']);

        $login = $this->postJson('/api/auth/login', ['email' => 'coach1@example.com', 'password' => 'a-chosen-password']);

        self::assertSame(200, $login['status']);
        self::assertTrue($login['body']['password_change_required']);
    }

    public function test_coach_cannot_set_password_for_a_member(): void
    {
        $coach = $this->createUser('coach2@example.com', role: UserRole::COACH);
        $member = $this->createUser('member2@example.com');

        $result = $this->postJson("/api/users/{$member->getId()}/set-password", [], $this->accessTokenFor($coach));

        self::assertSame(403, $result['status']);
    }

    public function test_member_cannot_set_own_password(): void
    {
        $member = $this->createUser('member3@example.com');

        $result = $this->postJson("/api/users/{$member->getId()}/set-password", [], $this->accessTokenFor($member));

        self::assertSame(403, $result['status']);
    }

    public function test_set_password_requires_authentication(): void
    {
        $member = $this->createUser('member5@example.com');

        $result = $this->postJson("/api/users/{$member->getId()}/set-password", []);

        self::assertSame(401, $result['status']);
    }

    public function test_forced_password_change_completes_and_login_no_longer_requires_it(): void
    {
        $owner = $this->createUser('owner3@example.com', role: UserRole::OWNER);
        $member = $this->createUser('member4@example.com');
        $setResult = $this->postJson("/api/users/{$member->getId()}/set-password", [], $this->accessTokenFor($owner));
        $generatedPassword = $setResult['body']['password'];

        $login = $this->postJson('/api/auth/login', ['email' => 'member4@example.com', 'password' => $generatedPassword]);
        self::assertTrue($login['body']['password_change_required']);

        $changeResult = $this->postJson('/api/auth/change-password', ['newPassword' => 'a-brand-new-password'], $login['body']['accessToken']);
        self::assertSame(200, $changeResult['status']);

        $secondLogin = $this->postJson('/api/auth/login', ['email' => 'member4@example.com', 'password' => 'a-brand-new-password']);

        self::assertSame(200, $secondLogin['status']);
        self::assertFalse($secondLogin['body']['password_change_required']);
    }

    public function test_change_password_without_current_password_when_not_required_is_rejected(): void
    {
        $user = $this->createUser('haspassword@example.com', password: 'existing-password', requiresPasswordChange: false);

        $result = $this->postJson('/api/auth/change-password', ['newPassword' => 'new-password-value'], $this->accessTokenFor($user));

        self::assertSame(400, $result['status']);
    }

    public function test_change_password_with_correct_current_password_succeeds(): void
    {
        $user = $this->createUser('haspassword2@example.com', password: 'existing-password', requiresPasswordChange: false);

        $result = $this->postJson('/api/auth/change-password', [
            'currentPassword' => 'existing-password',
            'newPassword' => 'new-password-value',
        ], $this->accessTokenFor($user));

        self::assertSame(200, $result['status']);

        $login = $this->postJson('/api/auth/login', ['email' => 'haspassword2@example.com', 'password' => 'new-password-value']);
        self::assertSame(200, $login['status']);
    }

    // ---- §3.2 Forgot / reset password ---------------------------------------

    public function test_forgot_password_for_unknown_identifier_returns_generic_response_and_creates_nothing(): void
    {
        $result = $this->postJson('/api/auth/forgot-password', ['identifier' => 'nobody-here@example.com']);

        self::assertSame(200, $result['status']);

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM password_reset_token');
        self::assertSame(0, $count);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $queued = array_filter($transport->getSent(), fn ($envelope) => $envelope->getMessage() instanceof SendPasswordResetCodeMessage);
        self::assertCount(0, $queued);
    }

    /**
     * The key requirement of this phase: forgot/reset works identically
     * for a user who has never set a password (OTP-only, password IS
     * NULL), and OTP login for that same user still works afterward.
     */
    public function test_otp_only_user_can_recover_via_forgot_password_and_otp_login_still_works_afterward(): void
    {
        $user = $this->createUser('otponly@example.com', '+15559990001');
        self::assertNull($user->getPasswordHash());

        $token = $this->requestResetAndCaptureToken('otponly@example.com');
        $reset = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'otponly@example.com',
            'token' => $token,
            'newPassword' => 'brand-new-password',
        ]);

        self::assertSame(200, $reset['status']);

        $login = $this->postJson('/api/auth/login', ['email' => 'otponly@example.com', 'password' => 'brand-new-password']);
        self::assertSame(200, $login['status']);
        self::assertFalse($login['body']['password_change_required']);

        // OTP login for the same user must still work, completely unaffected.
        $this->postJson('/api/auth/otp/request', ['destination' => 'otponly@example.com']);
        $messages = $this->getMailerMessages();
        $otpEmail = end($messages);
        preg_match('/\b(\d{6})\b/', $otpEmail->getTextBody(), $matches);
        $otpVerify = $this->postJson('/api/auth/otp/verify', ['destination' => 'otponly@example.com', 'code' => $matches[1]]);

        self::assertSame(200, $otpVerify['status']);
    }

    public function test_reset_password_with_expired_token_is_rejected(): void
    {
        $this->createUser('expiredreset@example.com', password: 'old-password', requiresPasswordChange: false);
        $token = $this->requestResetAndCaptureToken('expiredreset@example.com');

        $this->em->getConnection()->executeStatement(
            "UPDATE password_reset_token SET expires_at = now() - interval '1 minute'",
        );

        $result = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'expiredreset@example.com',
            'token' => $token,
            'newPassword' => 'new-password-value',
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_or_expired', $result['body']['error']);
    }

    public function test_reset_password_replayed_with_same_token_fails_the_second_time(): void
    {
        $this->createUser('replay@example.com', password: 'old-password', requiresPasswordChange: false);
        $token = $this->requestResetAndCaptureToken('replay@example.com');

        $first = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'replay@example.com',
            'token' => $token,
            'newPassword' => 'new-password-value',
        ]);
        self::assertSame(200, $first['status'], 'Sanity check: first use should succeed.');

        $second = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'replay@example.com',
            'token' => $token,
            'newPassword' => 'another-password-value',
        ]);

        self::assertSame(400, $second['status']);
        self::assertSame('invalid_or_expired', $second['body']['error']);
    }

    /** A fresh forgot-password request invalidates the previous outstanding token rather than stacking multiple valid ones. */
    public function test_a_new_forgot_password_request_invalidates_the_previous_token(): void
    {
        $this->createUser('retokened@example.com', password: 'old-password', requiresPasswordChange: false);
        $firstToken = $this->requestResetAndCaptureToken('retokened@example.com');
        $this->requestResetAndCaptureToken('retokened@example.com');

        $usingFirst = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'retokened@example.com',
            'token' => $firstToken,
            'newPassword' => 'new-password-value',
        ]);

        self::assertSame(400, $usingFirst['status']);
    }

    public function test_reset_password_revokes_all_refresh_tokens(): void
    {
        $this->createUser('revoketest@example.com', password: 'old-password', requiresPasswordChange: false);
        $login = $this->postJson('/api/auth/login', ['email' => 'revoketest@example.com', 'password' => 'old-password']);
        self::assertSame(200, $login['status']);

        $token = $this->requestResetAndCaptureToken('revoketest@example.com');
        $this->postJson('/api/auth/reset-password', [
            'identifier' => 'revoketest@example.com',
            'token' => $token,
            'newPassword' => 'new-password-value',
        ]);

        // Same browser session, same still-present refresh_token cookie from
        // the login above — must now be dead.
        $refreshResult = $this->postJson('/api/auth/refresh', []);

        self::assertSame(401, $refreshResult['status']);
    }

    public function test_forgot_password_beyond_rate_limit_returns_429(): void
    {
        $this->createUser('ratelimitreset@example.com', password: 'old-password', requiresPasswordChange: false);

        for ($i = 0; $i < 3; ++$i) {
            $attempt = $this->postJson('/api/auth/forgot-password', ['identifier' => 'ratelimitreset@example.com']);
            self::assertSame(200, $attempt['status'], "Request {$i} should still succeed.");
        }

        $fourth = $this->postJson('/api/auth/forgot-password', ['identifier' => 'ratelimitreset@example.com']);

        self::assertSame(429, $fourth['status']);
    }
}

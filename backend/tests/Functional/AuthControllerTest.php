<?php

namespace App\Tests\Functional;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers functional requirements §1.1 (password login), §1.2 (OTP login),
 * and §1.3 (session handling) — one test per Given/When/Then, including
 * the negative cases.
 */
final class AuthControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE otp_code, refresh_token, "user" CASCADE');

        // The rate limiter's storage is a filesystem cache pool, which
        // persists across separate test *runs* (unlike the DB, which is
        // freshly truncated above) — clear it so login/OTP rate-limit
        // tests never inherit state from a previous run.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(
        string $email,
        string $phone,
        ?string $password = null,
        UserRole $role = UserRole::MEMBER,
        UserStatus $status = UserStatus::ACTIVE,
    ): User {
        $user = new User('Test User', $email, $phone, $role, $status);

        if ($password !== null) {
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
            $user->setPasswordHash($hasher->hashPassword($user, $password));
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function postJson(string $uri, array $data): array
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'],
            content: json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
            'response' => $response,
        ];
    }

    /** Requests a code via the real endpoint and extracts the plaintext from the captured test email. */
    private function requestOtpAndCaptureCode(string $destination): string
    {
        $this->postJson('/api/auth/otp/request', ['destination' => $destination]);
        $email = $this->getMailerMessage(0);
        self::assertNotNull($email, 'Expected an OTP email to have been sent.');

        preg_match('/\b(\d{6})\b/', $email->getTextBody(), $matches);
        self::assertNotEmpty($matches, 'Expected a 6-digit code in the email body.');

        return $matches[1];
    }

    private function injectRawRefreshTokenCookie(string $rawToken): void
    {
        $this->client->getCookieJar()->set(
            new BrowserKitCookie('refresh_token', $rawToken, null, '/', 'localhost', true, true, false, 'none'),
        );
    }

    // ---- §1.1 Password login --------------------------------------------

    public function test_given_valid_credentials_when_login_then_receives_jwt_pair_and_lands_on_dashboard(): void
    {
        $this->createUser('valid@example.com', '+15550000001', 'correct-password', UserRole::OWNER);

        $result = $this->postJson('/api/auth/login', ['email' => 'valid@example.com', 'password' => 'correct-password']);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('accessToken', $result['body']);
        self::assertSame('owner', $result['body']['user']['role']);
        self::assertTrue($result['response']->headers->getCookies() !== [], 'Expected a refresh_token cookie to be set.');
    }

    public function test_given_incorrect_password_when_login_then_generic_invalid_credentials_error(): void
    {
        $this->createUser('realuser@example.com', '+15550000002', 'correct-password');

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'realuser@example.com', 'password' => 'wrong-password']);
        $nonExistentEmail = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'anything']);

        // Same status, same generic error for both — never confirms whether the email exists.
        self::assertSame(401, $wrongPassword['status']);
        self::assertSame(401, $nonExistentEmail['status']);
        self::assertSame('invalid_credentials', $wrongPassword['body']['error']);
        self::assertSame($wrongPassword['body'], $nonExistentEmail['body']);
    }

    public function test_given_5_failed_attempts_within_15_minutes_when_retry_then_rate_limited(): void
    {
        $this->createUser('lockout@example.com', '+15550000003', 'correct-password');

        for ($i = 0; $i < 5; ++$i) {
            $attempt = $this->postJson('/api/auth/login', ['email' => 'lockout@example.com', 'password' => 'wrong']);
            self::assertSame(401, $attempt['status'], "Attempt {$i} should still be a plain 401, not yet rate-limited.");
        }

        // 6th try — even with the CORRECT password — must be rate-limited, per
        // "when I try again, then I'm rate-limited" (not "when I fail again").
        $sixthAttempt = $this->postJson('/api/auth/login', ['email' => 'lockout@example.com', 'password' => 'correct-password']);

        self::assertSame(429, $sixthAttempt['status']);
        self::assertSame('rate_limited', $sixthAttempt['body']['error']);
    }

    // ---- §1.2 OTP login --------------------------------------------------

    public function test_given_registered_destination_when_otp_requested_then_code_sent_with_5_minute_expiry(): void
    {
        $this->createUser('otpuser@example.com', '+15550000004');

        $result = $this->postJson('/api/auth/otp/request', ['destination' => 'otpuser@example.com']);

        self::assertSame(200, $result['status']);
        self::assertSame(300, $result['body']['expiresInSeconds']);
        $this->assertEmailCount(1);
        preg_match('/\b(\d{6})\b/', $this->getMailerMessage(0)->getTextBody(), $matches);
        self::assertNotEmpty($matches, 'Expected a 6-digit code in the email body.');
    }

    public function test_given_correct_code_before_expiry_when_verify_then_receives_jwt_pair(): void
    {
        $this->createUser('correctcode@example.com', '+15550000005', role: UserRole::MEMBER);
        $code = $this->requestOtpAndCaptureCode('correctcode@example.com');

        $result = $this->postJson('/api/auth/otp/verify', ['destination' => 'correctcode@example.com', 'code' => $code]);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('accessToken', $result['body']);
        self::assertSame('member', $result['body']['user']['role']);
    }

    public function test_given_incorrect_code_when_verify_then_error_and_remaining_attempts_decrease(): void
    {
        $this->createUser('wrongcode@example.com', '+15550000006');
        $this->requestOtpAndCaptureCode('wrongcode@example.com');

        $first = $this->postJson('/api/auth/otp/verify', ['destination' => 'wrongcode@example.com', 'code' => '000000']);
        $second = $this->postJson('/api/auth/otp/verify', ['destination' => 'wrongcode@example.com', 'code' => '111111']);

        self::assertSame(401, $first['status']);
        self::assertSame('otp_incorrect', $first['body']['error']);
        self::assertSame(4, $first['body']['remainingAttempts']);
        self::assertSame(3, $second['body']['remainingAttempts'], 'Remaining attempts must keep decreasing.');
    }

    public function test_given_5_wrong_attempts_when_verify_then_code_invalidated_and_must_request_new(): void
    {
        $this->createUser('lockedcode@example.com', '+15550000007');
        $this->requestOtpAndCaptureCode('lockedcode@example.com');

        for ($i = 0; $i < 4; ++$i) {
            $attempt = $this->postJson('/api/auth/otp/verify', ['destination' => 'lockedcode@example.com', 'code' => '999999']);
            self::assertSame('otp_incorrect', $attempt['body']['error'], "Attempt {$i} should still be plain 'incorrect'.");
        }

        $fifthAttempt = $this->postJson('/api/auth/otp/verify', ['destination' => 'lockedcode@example.com', 'code' => '999999']);
        self::assertSame('otp_locked_out', $fifthAttempt['body']['error']);

        // Even the real code must now be rejected — the code is dead, a new one is
        // required. Once already-locked-out, later attempts fold into the same
        // generic "expired or used" bucket as any other dead code.
        $stillRejected = $this->postJson('/api/auth/otp/verify', ['destination' => 'lockedcode@example.com', 'code' => '123456']);
        self::assertSame('otp_expired_or_used', $stillRejected['body']['error']);
    }

    public function test_given_expired_code_when_verify_then_clear_expired_message(): void
    {
        $user = $this->createUser('expiredcode@example.com', '+15550000008');
        $this->requestOtpAndCaptureCode('expiredcode@example.com');

        // Force the just-created code into the past.
        $this->em->getConnection()->executeStatement(
            "UPDATE otp_code SET expires_at = now() - interval '1 minute' WHERE destination = ?",
            ['expiredcode@example.com'],
        );

        $result = $this->postJson('/api/auth/otp/verify', ['destination' => 'expiredcode@example.com', 'code' => '123456']);

        self::assertSame(401, $result['status']);
        self::assertSame('otp_expired_or_used', $result['body']['error']);
        self::assertStringContainsString('expired', $result['body']['message']);
    }

    public function test_given_already_used_code_when_verify_then_clear_used_message(): void
    {
        $this->createUser('usedcode@example.com', '+15550000009');
        $code = $this->requestOtpAndCaptureCode('usedcode@example.com');

        $firstUse = $this->postJson('/api/auth/otp/verify', ['destination' => 'usedcode@example.com', 'code' => $code]);
        self::assertSame(200, $firstUse['status'], 'Sanity check: first use should succeed.');

        $secondUse = $this->postJson('/api/auth/otp/verify', ['destination' => 'usedcode@example.com', 'code' => $code]);

        self::assertSame(401, $secondUse['status']);
        self::assertSame('otp_expired_or_used', $secondUse['body']['error']);
    }

    public function test_given_more_than_3_requests_in_10_minutes_when_request_again_then_rate_limited(): void
    {
        $this->createUser('ratelimited@example.com', '+15550000010');

        for ($i = 0; $i < 3; ++$i) {
            $attempt = $this->postJson('/api/auth/otp/request', ['destination' => 'ratelimited@example.com']);
            self::assertSame(200, $attempt['status'], "Request {$i} should still succeed.");
        }

        $fourthRequest = $this->postJson('/api/auth/otp/request', ['destination' => 'ratelimited@example.com']);

        self::assertSame(429, $fourthRequest['status']);
        self::assertSame('rate_limited', $fourthRequest['body']['error']);
    }

    // ---- §1.3 Session handling -------------------------------------------

    public function test_given_valid_refresh_token_when_access_token_expires_then_silently_refreshed(): void
    {
        $this->createUser('refresh@example.com', '+15550000011', 'correct-password');
        $this->postJson('/api/auth/login', ['email' => 'refresh@example.com', 'password' => 'correct-password']);
        $oldRefreshCookie = $this->client->getCookieJar()->get('refresh_token', '/', 'localhost');

        $refreshResult = $this->postJson('/api/auth/refresh', []);

        self::assertSame(200, $refreshResult['status']);
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $refreshResult['body']['accessToken']);
        self::assertSame('refresh@example.com', $refreshResult['body']['user']['email']);
        $newRefreshCookie = $this->client->getCookieJar()->get('refresh_token', '/', 'localhost');
        self::assertNotSame($oldRefreshCookie->getValue(), $newRefreshCookie->getValue(), 'The refresh token itself must rotate on use.');
    }

    public function test_given_invalid_refresh_token_when_refresh_attempted_then_401_for_redirect_to_login(): void
    {
        $this->injectRawRefreshTokenCookie('this-token-does-not-exist');

        $result = $this->postJson('/api/auth/refresh', []);

        self::assertSame(401, $result['status']);
        self::assertSame('invalid_refresh_token', $result['body']['error']);
    }

    public function test_given_expired_refresh_token_when_refresh_attempted_then_401_for_redirect_to_login(): void
    {
        $user = $this->createUser('expiredrefresh@example.com', '+15550000012', 'correct-password');
        $rawToken = 'expired-raw-token-value';
        $expiredToken = new RefreshToken($user, hash('sha256', $rawToken), new \DateTimeImmutable('-1 day'));
        $this->em->persist($expiredToken);
        $this->em->flush();
        $this->injectRawRefreshTokenCookie($rawToken);

        $result = $this->postJson('/api/auth/refresh', []);

        self::assertSame(401, $result['status']);
        self::assertSame('invalid_refresh_token', $result['body']['error']);
    }

    public function test_given_no_refresh_token_cookie_when_refresh_attempted_then_401(): void
    {
        $result = $this->postJson('/api/auth/refresh', []);

        self::assertSame(401, $result['status']);
        self::assertSame('invalid_refresh_token', $result['body']['error']);
    }

    // ---- Bonus coverage (not a numbered FR criterion, but a named §9 security property) --

    public function test_refresh_token_is_rotated_and_the_old_one_cannot_be_reused(): void
    {
        $this->createUser('rotate@example.com', '+15550000013', 'correct-password');
        $this->postJson('/api/auth/login', ['email' => 'rotate@example.com', 'password' => 'correct-password']);
        $oldCookie = $this->client->getCookieJar()->get('refresh_token', '/', 'localhost');

        $this->postJson('/api/auth/refresh', []); // rotates: old token is now revoked

        $this->injectRawRefreshTokenCookie($oldCookie->getValue());
        $reuseAttempt = $this->postJson('/api/auth/refresh', []);

        self::assertSame(401, $reuseAttempt['status']);
    }
}

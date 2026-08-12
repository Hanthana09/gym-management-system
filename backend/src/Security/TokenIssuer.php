<?php

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Issues the JWT access token + rotated, revocable refresh token pair
 * (architecture doc §6.1, §9). The refresh token's raw value only ever
 * exists in the httpOnly cookie; only its hash is persisted, the same
 * pattern used for OTP codes.
 */
class TokenIssuer
{
    public const REFRESH_COOKIE_NAME = 'refresh_token';
    private const REFRESH_TOKEN_TTL = '+30 days';

    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenRepository $refreshTokens,
    ) {
    }

    public function createAccessToken(User $user): string
    {
        return $this->jwtManager->create($user);
    }

    /** Issues a brand-new refresh token for a user (login / OTP verify). */
    public function issueRefreshCookie(User $user): Cookie
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(self::REFRESH_TOKEN_TTL);

        $refreshToken = new RefreshToken($user, $this->hash($rawToken), $expiresAt);
        $this->em->persist($refreshToken);
        $this->em->flush();

        return $this->buildCookie($rawToken, $expiresAt);
    }

    /**
     * Rotates an existing, already-validated refresh token: revokes it and
     * issues a fresh one for the same user.
     */
    public function rotateRefreshCookie(RefreshToken $current): Cookie
    {
        $current->revoke();
        $cookie = $this->issueRefreshCookie($current->getUser());
        $this->em->flush();

        return $cookie;
    }

    /** Resolves a raw cookie value to a valid, non-expired, non-revoked token — or null. */
    public function resolveValidRefreshToken(string $rawToken): ?RefreshToken
    {
        $token = $this->refreshTokens->findOneByTokenHash($this->hash($rawToken));

        return $token !== null && $token->isValid() ? $token : null;
    }

    public function expiredCookie(): Cookie
    {
        return Cookie::create(self::REFRESH_COOKIE_NAME)
            ->withValue(null)
            ->withExpires(1)
            // Root, not '/auth': the frontend dev server proxies API calls
            // under a '/api' prefix (vite.config.ts) so the browser sees
            // e.g. '/api/auth/refresh', not '/auth/refresh' — a cookie
            // scoped to '/auth' would never match that request path and
            // silently never get sent back. '/' has no such prefix
            // assumption to keep in sync with the proxy config.
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            // Frontend and backend are separate origins by design (architecture
            // doc §4) — Lax is blocked by Chromium's schemeful-same-site the
            // moment frontend/backend schemes differ (as they do in local dev:
            // Vite over http, FrankenPHP over https). None+Secure is the
            // standard pattern for a cross-origin SPA's credentialed cookie.
            ->withSameSite(Cookie::SAMESITE_NONE);
    }

    private function buildCookie(string $rawToken, \DateTimeImmutable $expiresAt): Cookie
    {
        return Cookie::create(self::REFRESH_COOKIE_NAME)
            ->withValue($rawToken)
            ->withExpires($expiresAt)
            // Root, not '/auth': the frontend dev server proxies API calls
            // under a '/api' prefix (vite.config.ts) so the browser sees
            // e.g. '/api/auth/refresh', not '/auth/refresh' — a cookie
            // scoped to '/auth' would never match that request path and
            // silently never get sent back. '/' has no such prefix
            // assumption to keep in sync with the proxy config.
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            // Frontend and backend are separate origins by design (architecture
            // doc §4) — Lax is blocked by Chromium's schemeful-same-site the
            // moment frontend/backend schemes differ (as they do in local dev:
            // Vite over http, FrankenPHP over https). None+Secure is the
            // standard pattern for a cross-origin SPA's credentialed cookie.
            ->withSameSite(Cookie::SAMESITE_NONE);
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}

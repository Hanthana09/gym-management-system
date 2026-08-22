<?php

namespace App\PasswordReset;

use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * gym-management-password-auth.md §3.2. Deliberately behaves identically
 * whether the target user already has a password or is OTP-only
 * (passwordHash === null) — there is nothing password-specific to
 * validate against in either case, that's the whole point of "forgot
 * password."
 */
class PasswordResetService
{
    private const TOKEN_TTL_MINUTES = 15;
    private const RAW_TOKEN_BYTES = 32;
    private const BASE62_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Always returns void regardless of whether the identifier matched an
     * account (functional requirements-style account enumeration
     * avoidance, same rule as OTP login) — the caller/controller returns
     * the same generic response either way.
     */
    public function requestReset(string $identifier, ?string $ip): void
    {
        $user = $this->users->findOneByEmail($identifier) ?? $this->users->findOneByPhone($identifier);
        if ($user === null) {
            return;
        }

        $this->tokens->invalidateOutstandingForUser($user);

        $rawToken = $this->generateRawToken();
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_TTL_MINUTES . ' minutes');
        $token = new PasswordResetToken($user, $this->hash($rawToken), $expiresAt, $ip);
        $this->em->persist($token);
        $this->em->flush();

        $this->bus->dispatch(new SendPasswordResetCodeMessage((string) $token->getId(), $identifier, $rawToken));
    }

    public function redeemReset(string $identifier, string $rawToken, string $newPassword): PasswordResetOutcome
    {
        $user = $this->users->findOneByEmail($identifier) ?? $this->users->findOneByPhone($identifier);
        if ($user === null) {
            return PasswordResetOutcome::INVALID;
        }

        $token = $this->tokens->findLatestUnusedForUser($user);
        if ($token === null || $token->isExpired() || !hash_equals($token->getTokenHash(), $this->hash($rawToken))) {
            return PasswordResetOutcome::INVALID;
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPassword));
        $user->setRequiresPasswordChange(false);
        $user->setPasswordSetBy(null);
        $user->setPasswordSetAt(new \DateTimeImmutable());
        $token->markUsed();
        $this->refreshTokens->revokeAllForUser($user);
        $this->em->flush();

        return PasswordResetOutcome::SUCCESS;
    }

    private function generateRawToken(): string
    {
        $bytes = random_bytes(self::RAW_TOKEN_BYTES);
        $token = '';
        foreach (str_split($bytes) as $byte) {
            $token .= self::BASE62_ALPHABET[ord($byte) % 62];
        }

        return $token;
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}

<?php

namespace App\Otp;

use App\Entity\User;

final class OtpVerificationResult
{
    private function __construct(
        public readonly OtpVerifyOutcome $outcome,
        public readonly ?User $user = null,
        public readonly int $remainingAttempts = 0,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(OtpVerifyOutcome::SUCCESS, $user);
    }

    public static function incorrect(int $remainingAttempts): self
    {
        return new self(OtpVerifyOutcome::INCORRECT, remainingAttempts: $remainingAttempts);
    }

    public static function lockedOut(): self
    {
        return new self(OtpVerifyOutcome::LOCKED_OUT);
    }

    public static function expiredOrUsed(): self
    {
        return new self(OtpVerifyOutcome::EXPIRED_OR_USED);
    }
}

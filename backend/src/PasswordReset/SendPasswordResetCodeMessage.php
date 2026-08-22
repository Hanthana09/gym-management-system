<?php

namespace App\PasswordReset;

/**
 * Dispatched by PasswordResetService::requestReset — mirrors the async
 * dispatch pattern used for notification delivery (config/packages/
 * messenger.yaml's `async` transport), so a slow provider never blocks
 * the request that triggered it. Carries the raw token because only its
 * hash is ever persisted (PasswordResetToken::$tokenHash).
 */
final class SendPasswordResetCodeMessage
{
    public function __construct(
        public readonly string $tokenId,
        public readonly string $destination,
        public readonly string $rawToken,
    ) {
    }
}

<?php

namespace App\Otp;

/**
 * Seam over the Email/SMS provider referenced in architecture doc §3.2 —
 * same "local now, swap later" pattern as file storage (§4/§10). Only the
 * email path is wired to a real transport in Phase 2; no SMS gateway
 * exists yet in this project.
 */
interface OtpDeliveryInterface
{
    public function send(string $destination, string $code): void;
}

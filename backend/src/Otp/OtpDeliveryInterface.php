<?php

namespace App\Otp;

/**
 * Seam over the Email/SMS provider referenced in architecture doc §3.2 —
 * same "local now, swap later" pattern as file storage (§4/§10). The
 * email path is wired to a real transport since Phase 2; no SMS gateway
 * exists in this project (see EmailOrWhatsAppOtpDelivery). The phone
 * path instead reuses the gym's WhatsApp Business Cloud API credentials
 * when configured — a pragmatic substitute for the SMS gateway that was
 * never built, not a doc-specified channel, and only ever a log-only
 * no-op when neither is configured.
 */
interface OtpDeliveryInterface
{
    public function send(string $destination, string $code): void;
}

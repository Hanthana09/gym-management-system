<?php

namespace App\Notification;

/**
 * roadmap Phase 15.3 / architecture doc §6.6: same "seam over the
 * provider, real now only where configured" role as
 * App\Otp\OtpDeliveryInterface (§3.2's SMS/email seam) — a swappable
 * boundary, not a speculative abstraction, since a WhatsApp Business
 * Cloud API integration and a Twilio one genuinely have different HTTP
 * shapes.
 */
interface WhatsAppSenderInterface
{
    public function send(string $toPhone, string $message): void;
}

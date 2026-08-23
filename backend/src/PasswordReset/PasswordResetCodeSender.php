<?php

namespace App\PasswordReset;

use App\Enum\ResetChannel;
use App\Notification\WhatsAppSenderInterface;
use App\Repository\GymRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * gym-management-password-auth.md §3.2 step 3 / roadmap's own note:
 * "reuses the existing channel-priority sender chain... do not write new
 * delivery logic." This mirrors App\Otp\EmailOrWhatsAppOtpDelivery's exact
 * branching (same MailerInterface/WhatsAppSenderInterface transports,
 * same gym-configured check) rather than calling that class directly —
 * OtpDeliveryInterface::send()'s message text is hardcoded to "Your
 * sign-in code is..." which would be actively misleading for a password
 * reset, and that interface is intentionally left untouched (this phase
 * must not change OTP login's own logic). No SMS gateway exists in this
 * project (see EmailOrWhatsAppOtpDelivery's docblock) — ResetChannel::SMS
 * is never actually reachable, same limitation as OTP.
 */
class PasswordResetCodeSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly WhatsAppSenderInterface $whatsapp,
        private readonly GymRepository $gyms,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $destination, string $rawToken): ResetChannel
    {
        if (filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            $email = (new Email())
                ->to($destination)
                ->from('no-reply@setly.fit')
                ->subject('Your password reset code')
                ->text("Your password reset code is {$rawToken}. It expires in 15 minutes. If you didn't request this, you can ignore this message.");

            $this->mailer->send($email);

            return ResetChannel::EMAIL;
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym !== null && $gym->isWhatsappConfigured()) {
            $this->whatsapp->send($destination, "Your password reset code is {$rawToken}. It expires in 15 minutes.");

            return ResetChannel::WHATSAPP;
        }

        // No SMS gateway is wired up yet (same gap as OTP delivery), and no
        // WhatsApp credentials are configured either — nothing is actually
        // delivered, but this is still recorded as the attempted channel
        // since ResetChannel has no "none" case and this field is audit
        // metadata only, never consulted by redemption logic.
        $this->logger->info('Password reset requested for a phone destination; no SMS gateway or WhatsApp credentials are configured.');

        return ResetChannel::WHATSAPP;
    }
}

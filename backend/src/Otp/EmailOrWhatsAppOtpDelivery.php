<?php

namespace App\Otp;

use App\Notification\WhatsAppSenderInterface;
use App\Repository\GymRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Renamed from MailerOtpDelivery: the phone branch used to be a
 * permanent log-only no-op ("no SMS gateway exists yet in this
 * project" — architecture doc §3.2 scopes OTP delivery to Email/SMS
 * only, deliberately excluding WhatsApp, which §6.6 scopes to
 * notifications). In practice that left phone-based OTP login
 * completely non-functional whenever no SMS gateway was ever going to
 * get built — and a gym that's already configured WhatsApp Business
 * Cloud API credentials (GymWhatsAppSettingsController) has a working,
 * already-configured channel to that same phone number sitting right
 * there unused. Reusing it here is deliberately narrower than the
 * notification channel's rules: gated only on the gym having
 * credentials configured (`isWhatsappConfigured()`), NOT on
 * `whatsappEnabled` (that flag's "opt-in, not opt-out" semantics are
 * about the Notification module's booking/billing fan-out, §6.6 —
 * a different concern from delivering a security code the user just
 * explicitly requested by submitting their phone number on the OTP
 * login screen) and NOT on the user's own `whatsappOptIn` (irrelevant
 * here — there may not even be an account yet, e.g. a first-time
 * invitee's OTP login, architecture doc §6.7). Falls back to the
 * original log-only no-op when no WhatsApp credentials are configured,
 * same "real when configured, log-only otherwise" pattern
 * WhatsAppCloudApiSender itself already follows.
 */
class EmailOrWhatsAppOtpDelivery implements OtpDeliveryInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly WhatsAppSenderInterface $whatsapp,
        private readonly GymRepository $gyms,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $destination, string $code): void
    {
        if (filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            $email = (new Email())
                ->to($destination)
                ->from('no-reply@setly.fit')
                ->subject('Your sign-in code')
                ->text("Your sign-in code is {$code}. It expires in 5 minutes.");

            $this->mailer->send($email);

            return;
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym !== null && $gym->isWhatsappConfigured()) {
            $this->whatsapp->send($destination, "Your sign-in code is {$code}. It expires in 5 minutes.");

            return;
        }

        // No SMS gateway is wired up yet (out of Phase 2 scope), and no
        // WhatsApp credentials are configured on the gym either — the
        // code itself is never logged (architecture doc §9), only this
        // notice.
        $this->logger->info('OTP requested for a phone destination; no SMS gateway or WhatsApp credentials are configured.');
    }
}

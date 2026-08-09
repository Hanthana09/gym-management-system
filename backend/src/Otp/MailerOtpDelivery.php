<?php

namespace App\Otp;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class MailerOtpDelivery implements OtpDeliveryInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $destination, string $code): void
    {
        if (filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            $email = (new Email())
                ->to($destination)
                ->from('no-reply@gym-management.local')
                ->subject('Your sign-in code')
                ->text("Your sign-in code is {$code}. It expires in 5 minutes.");

            $this->mailer->send($email);

            return;
        }

        // No SMS gateway is wired up yet (out of Phase 2 scope) — the code
        // itself is never logged (architecture doc §9), only this notice.
        $this->logger->info('OTP requested for a phone destination; SMS delivery is not yet configured.');
    }
}

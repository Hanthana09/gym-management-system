<?php

namespace App\Notification;

use App\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

/**
 * architecture doc §6.6: "Fans out to in-app (always), and email ... based
 * on user notification preferences." No notification-preferences concept
 * exists anywhere in the data model yet (not in §5.1, not named in this
 * phase's roadmap bullets) — inventing one would be scope creep, so this
 * simplifies to "email always sent, in-app always created," matching
 * EmailOrWhatsAppOtpDelivery's style for phone-only (no email) recipients.
 */
#[AsMessageHandler]
class SendNotificationEmailMessageHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendNotificationEmailMessage $message): void
    {
        $notification = $this->notifications->find($message->notificationId);
        if ($notification === null) {
            return; // deleted/race between dispatch and consumption — nothing to send
        }

        $email = $notification->getUser()->getEmail();
        if ($email === null) {
            $this->logger->info('Notification created for a phone-only user; email delivery skipped.');

            return;
        }

        $this->mailer->send((new Email())
            ->to($email)
            ->from('no-reply@setly.fit')
            ->subject($notification->getTitle())
            ->text($notification->getBody()));
    }
}

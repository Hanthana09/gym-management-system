<?php

namespace App\Notification;

use App\Repository\GymRepository;
use App\Repository\NotificationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * roadmap Phase 15.3: the opt-in/phone-eligibility check lives here, not
 * in NotificationService::notify() — same division of responsibility as
 * SendNotificationEmailMessageHandler (which unconditionally dispatches,
 * then skips inside the handler when the recipient has no email).
 * notify() always dispatching, this handler deciding whether to actually
 * call out, keeps NotificationService itself untouched by any future
 * channel's own eligibility rules.
 *
 * Admin-config follow-up: the gym-wide `whatsappEnabled` master switch
 * is checked here too, alongside the per-user opt-in — an Owner turning
 * the channel off should stop every send immediately, regardless of
 * individual opt-in state.
 */
#[AsMessageHandler]
class SendNotificationWhatsAppMessageHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly GymRepository $gyms,
        private readonly WhatsAppSenderInterface $sender,
    ) {
    }

    public function __invoke(SendNotificationWhatsAppMessage $message): void
    {
        $notification = $this->notifications->find($message->notificationId);
        if ($notification === null) {
            return; // deleted/race between dispatch and consumption — nothing to send
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null || !$gym->isWhatsappEnabled()) {
            return;
        }

        $user = $notification->getUser();
        $phone = $user->getPhone();
        if (!$user->isWhatsappOptIn() || $phone === null) {
            return;
        }

        $this->sender->send($phone, $notification->getTitle() . "\n" . $notification->getBody());
    }
}

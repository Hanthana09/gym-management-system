<?php

namespace App\Notification;

use App\Repository\GymRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WhatsApp Business Cloud API (Meta's Graph API) — mirrors
 * App\Otp\MailerOtpDelivery's exact shape: a real HTTP call when
 * configured, a log-only no-op otherwise. Credentials live on the single
 * Gym row (Owner-configurable via GymWhatsAppSettingsController), not
 * env vars — a gym-level admin setting, not deployment config, since
 * different gyms on this product would eventually want their own
 * WhatsApp Business number. Outbound-only per roadmap Phase 15.3: no
 * webhook/inbound handling here, that's a separate, out-of-scope
 * feature.
 */
class WhatsAppCloudApiSender implements WhatsAppSenderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly GymRepository $gyms,
    ) {
    }

    public function send(string $toPhone, string $message): void
    {
        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null || !$gym->isWhatsappConfigured()) {
            $this->logger->info('WhatsApp requested but no access token/phone number ID is configured on the gym; delivery skipped.');

            return;
        }

        $this->httpClient->request(
            'POST',
            "https://graph.facebook.com/v20.0/{$gym->getWhatsappPhoneNumberId()}/messages",
            [
                'auth_bearer' => $gym->getWhatsappAccessToken(),
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $toPhone,
                    'type' => 'text',
                    'text' => ['body' => $message],
                ],
            ],
        );
    }
}

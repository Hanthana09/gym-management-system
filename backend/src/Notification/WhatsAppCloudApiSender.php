<?php

namespace App\Notification;

use App\Repository\GymRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WhatsApp Business Cloud API (Meta's Graph API) — mirrors
 * App\Otp\EmailOrWhatsAppOtpDelivery's exact shape: a real HTTP call
 * when configured, a log-only no-op otherwise (that class is now one
 * of this one's two callers — the other being phone-based OTP
 * delivery). Credentials live on the single Gym row (Owner-configurable
 * via GymWhatsAppSettingsController), not env vars — a gym-level admin
 * setting, not deployment config, since different gyms on this product
 * would eventually want their own WhatsApp Business number.
 * Outbound-only per roadmap Phase 15.3: no webhook/inbound handling
 * here, that's a separate, out-of-scope feature.
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

        // A failed send (expired/invalid token, Meta API downtime, rate
        // limiting) must never propagate out of here — this is now
        // called synchronously from the OTP request endpoint (Otp\
        // EmailOrWhatsAppOtpDelivery), not just from the async
        // Messenger-queued notification path, so an uncaught exception
        // here would 500 a user's login attempt instead of just failing
        // one queued message. Same "log and move on" resilience as the
        // "not configured" branch above.
        //
        // getStatusCode() itself never throws (only getHeaders()/
        // getContent()/toArray() do, by default) — checked explicitly
        // instead of relying on a lazy exception, so a >=400 response is
        // always caught here regardless of how eagerly anything else
        // touches the response.
        try {
            $response = $this->httpClient->request(
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

            if ($response->getStatusCode() >= 400) {
                $this->logger->error('WhatsApp message delivery failed.', [
                    'statusCode' => $response->getStatusCode(),
                    'body' => $response->getContent(false),
                ]);
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('WhatsApp message delivery failed.', ['exception' => $e]);
        }
    }
}

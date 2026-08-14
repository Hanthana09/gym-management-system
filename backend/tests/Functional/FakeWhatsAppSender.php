<?php

namespace App\Tests\Functional;

use App\Notification\WhatsAppSenderInterface;

/** Test double swapped in for WhatsAppCloudApiSender via the test container — records calls instead of hitting the real Graph API. */
final class FakeWhatsAppSender implements WhatsAppSenderInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $toPhone, string $message): void
    {
        $this->sent[] = ['to' => $toPhone, 'message' => $message];
    }
}

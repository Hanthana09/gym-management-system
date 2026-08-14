<?php

namespace App\Notification;

/**
 * roadmap Phase 15.3 — same shape as SendNotificationEmailMessage (id
 * only, not the entity itself, since Messenger serializes messages for
 * the queue and a Doctrine entity reference would go stale).
 */
final class SendNotificationWhatsAppMessage
{
    public function __construct(public readonly string $notificationId)
    {
    }
}

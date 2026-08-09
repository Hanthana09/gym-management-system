<?php

namespace App\Notification;

/**
 * architecture doc §6.6: "Delivery is async via a Symfony Messenger queue
 * so a slow email provider never blocks the API request." Carries just
 * the id (not the notification itself) since Messenger serializes
 * messages for the queue — a Doctrine entity reference would go stale.
 */
final class SendNotificationEmailMessage
{
    public function __construct(public readonly string $notificationId)
    {
    }
}

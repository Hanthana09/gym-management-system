<?php

namespace App\Enum;

/** Matches architecture doc §5.1's NOTIFICATION.type enum exactly. */
enum NotificationType: string
{
    case BOOKING = 'booking';
    case BILLING = 'billing';
    case ANNOUNCEMENT = 'announcement';
    case SYSTEM = 'system';
}

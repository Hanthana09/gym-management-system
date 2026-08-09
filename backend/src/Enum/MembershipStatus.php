<?php

namespace App\Enum;

/**
 * architecture doc §5.1 only lists active|paused|expired. `cancelled` is
 * added here: functional requirements §3.3 distinguishes "pause" (paused,
 * resumable) from "cancel" (deliberate, permanent) as two different member
 * actions, and collapsing cancel into `expired` would make it impossible
 * to tell "ran out naturally" apart from "member chose to end it" — a
 * real reporting question an Owner would reasonably ask.
 */
enum MembershipStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
}

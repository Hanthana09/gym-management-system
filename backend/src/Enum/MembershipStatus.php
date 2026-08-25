<?php

namespace App\Enum;

/**
 * architecture doc §5.1 only lists active|paused|expired. `cancelled` is
 * added here: functional requirements §3.3 distinguishes "pause" (paused,
 * resumable) from "cancel" (deliberate, permanent) as two different member
 * actions, and collapsing cancel into `expired` would make it impossible
 * to tell "ran out naturally" apart from "member chose to end it" — a
 * real reporting question an Owner would reasonably ask.
 *
 * `suspended` is added by gym-management-billing-v1.md §4.2 — an
 * Owner/Staff enforcement action (e.g. non-payment), distinct from the
 * member-initiated, resumable `paused`.
 */
enum MembershipStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case SUSPENDED = 'suspended';
}

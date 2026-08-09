<?php

namespace App\Enum;

/**
 * architecture doc §5.1 only lists pending|confirmed|completed|cancelled.
 * `declined` is added here: §6.4/§8.2 both name a distinct `session.declined`
 * event fired when a Coach turns down a request, and functional
 * requirements §5.1/§5.2 need to tell "Member cancelled their own pending
 * request" apart from "Coach declined it" — collapsing both into
 * `cancelled` would make the Member's own list ambiguous about who acted,
 * the same reasoning MembershipStatus already applied for `cancelled`
 * vs `expired`.
 */
enum PtSessionStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case COMPLETED = 'completed';
    case DECLINED = 'declined';
    case CANCELLED = 'cancelled';
}

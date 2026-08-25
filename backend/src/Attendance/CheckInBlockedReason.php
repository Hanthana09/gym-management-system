<?php

namespace App\Attendance;

/**
 * functional requirements §4.1 names three: expired, paused, suspended.
 * `suspended` is the User account being suspended by the Owner
 * (architecture doc §6.7's "Owner-initiated suspensions"), not a
 * Membership status — Membership only has active|paused|expired|cancelled
 * (Phase 4). `no_membership` and `cancelled` aren't named in the FR text
 * but are necessary: a member who was never enrolled, or whose membership
 * was cancelled, isn't "active" either and must be blocked too.
 *
 * The last three are gym-management-billing-v1.md §5.5's exact reason
 * strings (the frontend's BLOCKED_REASON_LABELS maps key off these
 * literal values): `subscription_inactive` for a SUSPENDED membership,
 * `absent_invoice`/`overdue` from CheckInEligibilityChecker.
 */
enum CheckInBlockedReason: string
{
    case ACCOUNT_SUSPENDED = 'account_suspended';
    case NO_MEMBERSHIP = 'no_membership';
    case MEMBERSHIP_EXPIRED = 'membership_expired';
    case MEMBERSHIP_PAUSED = 'membership_paused';
    case MEMBERSHIP_CANCELLED = 'membership_cancelled';
    case SUBSCRIPTION_INACTIVE = 'subscription_inactive';
    case ABSENT_INVOICE = 'absent_invoice';
    case OVERDUE = 'overdue';
}

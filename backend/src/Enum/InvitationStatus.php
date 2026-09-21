<?php

namespace App\Enum;

enum InvitationStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case EXPIRED = 'expired';
    /** Owner-initiated close, distinct from DECLINED (the invitee's own choice) — see InvitationVoter::CANCEL. */
    case CANCELLED = 'cancelled';
}

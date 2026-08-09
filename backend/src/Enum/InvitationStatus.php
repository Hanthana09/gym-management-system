<?php

namespace App\Enum;

enum InvitationStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case EXPIRED = 'expired';
}

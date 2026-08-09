<?php

namespace App\Enum;

enum UserStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}

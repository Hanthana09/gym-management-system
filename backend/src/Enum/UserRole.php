<?php

namespace App\Enum;

enum UserRole: string
{
    case OWNER = 'owner';
    case COACH = 'coach';
    case STAFF = 'staff';
    case MEMBER = 'member';
}

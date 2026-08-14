<?php

namespace App\Enum;

enum InvitationRole: string
{
    case COACH = 'coach';
    case STAFF = 'staff';
    case MEMBER = 'member';

    public function toUserRole(): UserRole
    {
        return match ($this) {
            self::COACH => UserRole::COACH,
            self::STAFF => UserRole::STAFF,
            self::MEMBER => UserRole::MEMBER,
        };
    }
}

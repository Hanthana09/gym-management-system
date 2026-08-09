<?php

namespace App\Enum;

enum InvitationRole: string
{
    case COACH = 'coach';
    case MEMBER = 'member';

    public function toUserRole(): UserRole
    {
        return match ($this) {
            self::COACH => UserRole::COACH,
            self::MEMBER => UserRole::MEMBER,
        };
    }
}

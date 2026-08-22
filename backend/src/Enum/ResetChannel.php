<?php

namespace App\Enum;

/** gym-management-password-auth.md §2.2: which channel actually delivered a password reset code. */
enum ResetChannel: string
{
    case WHATSAPP = 'whatsapp';
    case EMAIL = 'email';
    case SMS = 'sms';
}

<?php

namespace App\PasswordReset;

enum PasswordResetOutcome
{
    case SUCCESS;
    /** Unknown identifier, wrong token, expired, or already used — deliberately one bucket (§6: never distinguish which). */
    case INVALID;
}

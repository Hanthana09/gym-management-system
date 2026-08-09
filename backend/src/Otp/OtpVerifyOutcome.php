<?php

namespace App\Otp;

enum OtpVerifyOutcome
{
    case SUCCESS;
    /** Wrong code, attempts remain — functional requirements §1.2. */
    case INCORRECT;
    /** Wrong code and the 5th attempt was just used — code is now dead. */
    case LOCKED_OUT;
    /** Already expired, already consumed, previously locked out, or never existed. */
    case EXPIRED_OR_USED;
}

<?php

namespace App\Attendance;

class CheckInBlockedException extends \RuntimeException
{
    public function __construct(public readonly CheckInBlockedReason $reason, string $message)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Membership;

/** Covers both "already enrolled" and "invalid status transition" (e.g. resuming a membership that isn't paused). */
class MembershipConflictException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

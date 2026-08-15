<?php

namespace App\Branch;

/** Same shape as BranchAssignmentConflictException — a 409, not a 400 or 500. */
class BranchDeletionConflictException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

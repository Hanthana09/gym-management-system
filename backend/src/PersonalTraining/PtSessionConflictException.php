<?php

namespace App\PersonalTraining;

/** Covers "invalid status transition" — responding to or cancelling a request that isn't pending anymore. */
class PtSessionConflictException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

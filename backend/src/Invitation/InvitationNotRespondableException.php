<?php

namespace App\Invitation;

class InvitationNotRespondableException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invitation is not respondable: {$reason}");
    }
}

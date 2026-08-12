<?php

namespace App\Billing;

/** e.g. attempting to mark an already-paid invoice paid again. */
class InvoiceConflictException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

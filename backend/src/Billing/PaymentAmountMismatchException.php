<?php

namespace App\Billing;

/**
 * gym-management-billing-v1.md §5.2 point 2 — amount != invoice.amount is
 * a hard rejection (422), never a partial credit. Separate from
 * InvoiceConflictException (409, "wrong state to pay at all") since this
 * is "right state, wrong amount" and needs its own HTTP status.
 */
class PaymentAmountMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Partial payments are not supported. Enter the full amount due.');
    }
}

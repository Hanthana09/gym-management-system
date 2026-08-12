<?php

namespace App\Enum;

/** Matches architecture doc §5.1's INVOICE.status enum exactly. */
enum InvoiceStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
}

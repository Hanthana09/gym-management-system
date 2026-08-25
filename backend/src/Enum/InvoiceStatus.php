<?php

namespace App\Enum;

/**
 * Matches architecture doc §5.1's INVOICE.status enum, plus `absent` —
 * gym-management-billing-v1.md §4.1: a recurring invoice that was still
 * PENDING when its next cycle's invoice was generated. System-triggered
 * only (InvoiceGenerationService), never set from a client request.
 * `failed` stays for the existing one-time flow's use, unused by the
 * recurring flow.
 */
enum InvoiceStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case ABSENT = 'absent';
}

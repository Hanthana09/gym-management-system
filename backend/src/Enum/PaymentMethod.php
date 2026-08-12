<?php

namespace App\Enum;

/**
 * architecture doc §5.1 lists cash|bank_transfer|gateway. `referral_credit`
 * is added here — Phase 9.2's `ReferralCode` earns a "free month" credit
 * that Phase 10 (this phase) now has a real billing surface to apply to
 * (see `BillingService::issueInvoiceForMembership()`). It's set only by
 * that internal, automatic path, never accepted from the Owner's manual
 * mark-paid request (which stays limited to cash/bank_transfer per
 * functional requirements §8.1 — `gateway` is reserved for the deferred
 * gateway integration, architecture doc §6.9).
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case GATEWAY = 'gateway';
    case REFERRAL_CREDIT = 'referral_credit';
}

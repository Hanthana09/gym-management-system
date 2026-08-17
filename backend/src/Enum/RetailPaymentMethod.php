<?php

namespace App\Enum;

/**
 * roadmap Phase 17 / architecture doc §5.1's PRODUCT_SALE.payment_method:
 * "cash | card | other — mirrors INVOICE.payment_method's shape without
 * sharing its table." A separate enum from App\Enum\PaymentMethod on
 * purpose — that one carries `gateway`/`referral_credit`, both meaningless
 * for a retail sale (no gateway integration, no referral-credit billing
 * path here — §6.13's explicit "purely additive, never touches Invoice"
 * rule), and sharing it would let a retail sale accidentally accept a
 * billing-only value.
 */
enum RetailPaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case OTHER = 'other';
}

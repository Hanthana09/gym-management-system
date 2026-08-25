<?php

namespace App\Billing;

/**
 * gym-management-billing-v1.md §5.1's anchor-day advance/clamp logic —
 * shared by enrollment (first cycle), InvoiceGenerationService (each
 * subsequent cycle), and BillingService::recordRecurringPayment()'s
 * resetBillingCycle path, so the clamping rule only lives in one place.
 */
final class BillingCycleCalculator
{
    /**
     * Next calendar month from $from, on day $anchorDay — clamped to that
     * month's last day if $anchorDay exceeds it (e.g. anchor day 31 in a
     * 30-day month → last day of that month, no exception).
     */
    public static function advance(\DateTimeImmutable $from, int $anchorDay): \DateTimeImmutable
    {
        $firstOfNextMonth = $from->modify('first day of next month');
        $daysInMonth = (int) $firstOfNextMonth->format('t');
        $clampedDay = min($anchorDay, $daysInMonth);

        return $firstOfNextMonth->modify('+' . ($clampedDay - 1) . ' days');
    }
}

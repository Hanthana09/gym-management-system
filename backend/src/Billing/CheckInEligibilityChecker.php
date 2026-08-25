<?php

namespace App\Billing;

use App\Attendance\CheckInBlockedReason;
use App\Entity\Membership;
use App\Repository\InvoiceRepository;

/**
 * gym-management-billing-v1.md §5.5 rules 2-3 (billing-specific gating).
 * Rule 1 (subscription/membership status != ACTIVE) is deliberately left
 * to AttendanceService's existing MembershipStatus match — SUSPENDED is
 * just one more case there, alongside EXPIRED/PAUSED/CANCELLED, rather
 * than duplicating a status check here too. Callers (AttendanceService,
 * the billing-status endpoint) only ever call this once the membership is
 * already confirmed ACTIVE.
 */
class CheckInEligibilityChecker
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    /** @return CheckInBlockedReason|null null = eligible to check in */
    public function check(Membership $membership): ?CheckInBlockedReason
    {
        if ($this->invoices->hasAbsentInvoice($membership)) {
            return CheckInBlockedReason::ABSENT_INVOICE;
        }

        // Evaluated live against today's date — not dependent on the
        // nightly generation command having run yet (§5.5's explicit
        // note: still formally PENDING the day after dueDate, but already
        // blocking).
        if ($this->invoices->hasOverduePendingInvoice($membership, new \DateTimeImmutable('today'))) {
            return CheckInBlockedReason::OVERDUE;
        }

        return null;
    }
}

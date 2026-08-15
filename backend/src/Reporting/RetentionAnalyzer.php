<?php

namespace App\Reporting;

use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Repository\AttendanceLogRepository;
use App\Repository\MembershipRepository;

/**
 * architecture doc §6.8 / functional requirements §10.4: rules-based,
 * explainable retention signals — no ML, no external prediction service.
 * Every signal below is a plain, named threshold a human can read and
 * argue with, and every match produces a human-readable reason string,
 * never a bare score (§10.4's own non-negotiable requirement).
 *
 * `asOf` is always a calendar day (midnight-normalized — "as of the end
 * of this day"), never a precise instant: membership start/end dates are
 * DATE columns, so term checks need date-only comparisons, and check-ins
 * are counted through the end of that calendar day. For the live
 * retention list this is `today`; for DailyMetricAggregator's backfill
 * it's the historical day being computed — same method, same semantics,
 * so a backfilled day's at_risk_members_count reflects what was actually
 * true then, not today's state projected backwards.
 */
class RetentionAnalyzer
{
    private const NO_CHECKIN_DAYS_THRESHOLD = 14;
    private const EXPIRY_WARNING_DAYS = 7;
    private const FREQUENCY_WINDOW_DAYS = 14;
    private const FREQUENCY_DROP_RATIO = 0.5;
    private const MIN_TENURE_DAYS_FOR_FREQUENCY_SIGNAL = 30;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly AttendanceLogRepository $attendanceLogs,
    ) {
    }

    /**
     * @return array<array{member: MemberProfile, membership: Membership, reasons: string[]}>
     */
    public function atRiskMembers(\DateTimeImmutable $asOf, ?Branch $branch = null): array
    {
        $result = [];
        foreach ($this->memberships->findWithinTermAsOf($asOf, $branch) as $membership) {
            $reasons = $this->evaluate($membership, $asOf);
            if ($reasons !== []) {
                $result[] = [
                    'member' => $membership->getMember(),
                    'membership' => $membership,
                    'reasons' => $reasons,
                ];
            }
        }

        return $result;
    }

    /** @return string[] */
    public function evaluate(Membership $membership, \DateTimeImmutable $asOf): array
    {
        $reasons = [];
        $checkIns = $this->checkInsAsOf($membership->getMember(), $asOf);
        $tenureDays = (int) $asOf->diff($membership->getStartDate())->format('%a');

        $noCheckInReason = $this->noCheckInSignal($checkIns, $tenureDays, $asOf);
        if ($noCheckInReason !== null) {
            $reasons[] = $noCheckInReason;
        }

        $expirySignal = $this->expiryWithoutRenewalSignal($membership, $asOf);
        if ($expirySignal !== null) {
            $reasons[] = $expirySignal;
        }

        $frequencySignal = $this->frequencyDropSignal($checkIns, $tenureDays, $asOf);
        if ($frequencySignal !== null) {
            $reasons[] = $frequencySignal;
        }

        return $reasons;
    }

    /** @return AttendanceLog[] newest-first, including all of $asOf's calendar day but nothing after */
    private function checkInsAsOf(MemberProfile $member, \DateTimeImmutable $asOf): array
    {
        $endOfDayExclusive = $asOf->modify('+1 day');

        return array_values(array_filter(
            $this->attendanceLogs->findAllForMember($member),
            fn (AttendanceLog $log) => $log->getCheckIn() < $endOfDayExclusive,
        ));
    }

    /** Signal: no check-in in the last N days (or none ever, for a member who's been around long enough to expect one). */
    private function noCheckInSignal(array $checkIns, int $tenureDays, \DateTimeImmutable $asOf): ?string
    {
        if ($checkIns === []) {
            if ($tenureDays >= self::NO_CHECKIN_DAYS_THRESHOLD) {
                return sprintf('No check-ins recorded since joining %d days ago', $tenureDays);
            }

            return null;
        }

        $daysSinceLastCheckIn = (int) $asOf->diff($checkIns[0]->getCheckIn())->format('%a');
        if ($daysSinceLastCheckIn >= self::NO_CHECKIN_DAYS_THRESHOLD) {
            return sprintf('No check-in in %d days', $daysSinceLastCheckIn);
        }

        return null;
    }

    /** Signal: membership nearing expiry without a renewal action (auto-renew off). */
    private function expiryWithoutRenewalSignal(Membership $membership, \DateTimeImmutable $asOf): ?string
    {
        if ($membership->isAutoRenew()) {
            return null;
        }

        $daysUntilExpiry = (int) $asOf->diff($membership->getEndDate())->format('%r%a');
        if ($daysUntilExpiry >= 0 && $daysUntilExpiry <= self::EXPIRY_WARNING_DAYS) {
            return sprintf('Membership expires in %d day%s with auto-renew off', $daysUntilExpiry, $daysUntilExpiry === 1 ? '' : 's');
        }

        return null;
    }

    /** Signal: check-in frequency well below the member's own historical average — needs enough tenure to have a meaningful average. */
    private function frequencyDropSignal(array $checkIns, int $tenureDays, \DateTimeImmutable $asOf): ?string
    {
        if ($tenureDays < self::MIN_TENURE_DAYS_FOR_FREQUENCY_SIGNAL) {
            return null;
        }

        $windowStart = $asOf->modify('-' . self::FREQUENCY_WINDOW_DAYS . ' days');
        $recentCount = count(array_filter($checkIns, fn (AttendanceLog $log) => $log->getCheckIn() >= $windowStart));
        $historicalAverage = count($checkIns) / ($tenureDays / self::FREQUENCY_WINDOW_DAYS);

        // Below 1 check-in per window on average, there's no meaningful
        // "usual pace" to have dropped from — the no-check-in signal
        // above already covers a member who's stopped coming entirely.
        if ($historicalAverage < 1.0) {
            return null;
        }

        if ($recentCount < $historicalAverage * self::FREQUENCY_DROP_RATIO) {
            return sprintf(
                'Check-ins dropped to %d in the last %d days, vs. their %.1f average',
                $recentCount,
                self::FREQUENCY_WINDOW_DAYS,
                $historicalAverage,
            );
        }

        return null;
    }
}

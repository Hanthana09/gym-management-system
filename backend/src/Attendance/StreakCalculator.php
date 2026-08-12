<?php

namespace App\Attendance;

use App\Entity\MemberProfile;
use App\Repository\AttendanceLogRepository;

/**
 * roadmap Phase 9.3: "start with just check-in streaks." A new file, not
 * a change to AttendanceService — the existing check-in module (Phase 5)
 * stays untouched; this only reads what it already wrote via
 * AttendanceLogRepository (itself extended with one new read-only finder,
 * not a modified one).
 */
class StreakCalculator
{
    public function __construct(private readonly AttendanceLogRepository $logs)
    {
    }

    /** Consecutive calendar days with at least one check-in, ending on $asOf (0 if today isn't checked in). */
    public function currentStreakDays(MemberProfile $member, \DateTimeImmutable $asOf): int
    {
        $distinctDates = [];
        foreach ($this->logs->findAllForMember($member) as $log) {
            $distinctDates[$log->getCheckIn()->format('Y-m-d')] = true;
        }
        // Insertion order follows the newest-first query order; array keys
        // dedupe same-day entries without disturbing that order.
        $dateKeys = array_keys($distinctDates);

        $streak = 0;
        $expected = $asOf->format('Y-m-d');
        foreach ($dateKeys as $dateKey) {
            if ($dateKey === $expected) {
                ++$streak;
                $expected = (new \DateTimeImmutable($expected))->modify('-1 day')->format('Y-m-d');
            } elseif ($dateKey < $expected) {
                break;
            }
        }

        return $streak;
    }
}

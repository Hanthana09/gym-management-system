<?php

namespace App\Event;

use App\Entity\AttendanceLog;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Check-in-timer feature: drives the member top bar's Mercure sync (a
 * checkout from another device/tab freezes the timer without a manual
 * refresh). Unlike AttendanceCheckedInEvent, this carries no Gym — the
 * only listener that needs one (the Owner's live gym-wide counter) isn't
 * affected by checkout, since that counter only ever counted check-ins.
 */
final class AttendanceCheckedOutEvent extends Event
{
    public const NAME = 'attendance.checked_out';

    public function __construct(
        private readonly AttendanceLog $log,
    ) {
    }

    public function getLog(): AttendanceLog
    {
        return $this->log;
    }
}

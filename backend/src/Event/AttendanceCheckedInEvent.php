<?php

namespace App\Event;

use App\Entity\AttendanceLog;
use App\Entity\Gym;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * architecture doc §6.3 / §8.1: "emit attendance.checked_in" — used for
 * the Owner's live dashboard counter via Mercure. This is that feature's
 * own live-sync push, not the async Notification module (same split as
 * Phase 3's InvitationMercurePublisher and Phase 4's expiry events).
 */
final class AttendanceCheckedInEvent extends Event
{
    public const NAME = 'attendance.checked_in';

    public function __construct(
        private readonly AttendanceLog $log,
        private readonly Gym $gym,
    ) {
    }

    public function getLog(): AttendanceLog
    {
        return $this->log;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }
}

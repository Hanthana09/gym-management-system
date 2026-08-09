<?php

namespace App\Event;

use App\Entity\Membership;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * architecture doc §8.3: fired once per matched threshold (7, 3, or 1 day
 * before end_date) by the daily scheduled scan. The Notification module
 * (Phase 7, already wired to earlier phases' events) subscribes to this on
 * its own — nothing here calls it directly.
 */
final class MembershipExpiringEvent extends Event
{
    public const NAME = 'membership.expiring';

    public function __construct(
        private readonly Membership $membership,
        private readonly int $daysUntilExpiry,
    ) {
    }

    public function getMembership(): Membership
    {
        return $this->membership;
    }

    public function getDaysUntilExpiry(): int
    {
        return $this->daysUntilExpiry;
    }
}

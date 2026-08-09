<?php

namespace App\Membership;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * architecture doc §8.3: "daily scan". Runs at 02:00 — after any
 * reasonable end-of-day billing/reporting cutoff, before the gym opens.
 * Requires a running `messenger:consume scheduler_membership` worker in
 * production; see MembershipExpiryScanner for the logic itself, which is
 * what's actually under test.
 */
#[AsSchedule('membership')]
class MembershipScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron('0 2 * * *', new RunMembershipExpiryScanMessage()),
        );
    }
}

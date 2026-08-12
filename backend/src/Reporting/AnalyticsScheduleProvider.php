<?php

namespace App\Reporting;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * architecture doc §6.8, same pattern as MembershipScheduleProvider
 * (§8.3). Runs at 02:30 — after the 02:00 membership-expiry scan, so
 * yesterday's snapshot reflects any lazy active→expired transitions that
 * scan settles (not that DailyMetricAggregator strictly depends on that
 * ordering — its date-range queries are correct either way — but running
 * after keeps the two jobs' pictures of "yesterday" consistent).
 * Requires a running `messenger:consume scheduler_analytics` worker in
 * production; see DailyMetricAggregator for the logic itself.
 */
#[AsSchedule('analytics')]
class AnalyticsScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron('30 2 * * *', new RunDailyMetricAggregationMessage()),
        );
    }
}

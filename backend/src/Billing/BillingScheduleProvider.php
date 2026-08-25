<?php

namespace App\Billing;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * gym-management-billing-v1.md §5.1. The spec asks to "reuse the same
 * cron slot planned for the Phase 11 DAILY_METRIC_SNAPSHOT job" — that
 * job (AnalyticsScheduleProvider, '30 2 * * *') is out of scope to modify
 * here (Phase 11 is explicitly excluded from this phase). This runs in
 * the same window instead: after the 02:00 membership-expiry scan,
 * before the 02:30 analytics run. Requires a running
 * `messenger:consume scheduler_billing` worker in production, same as
 * the membership/analytics jobs.
 */
#[AsSchedule('billing')]
class BillingScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron('15 2 * * *', new RunBillingGenerationMessage()),
        );
    }
}

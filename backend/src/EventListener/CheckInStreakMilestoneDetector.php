<?php

namespace App\EventListener;

use App\Attendance\StreakCalculator;
use App\Event\AttendanceCheckedInEvent;
use App\Event\MemberMilestoneReachedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * roadmap Phase 9.3 (GTM Pillar D). Listens to the existing, unmodified
 * attendance.checked_in event (Phase 5) and emits member.milestone_reached
 * the first time a streak crosses one of a small, fixed set of
 * thresholds — go-to-market §7's own guidance is "don't guess this
 * upfront," so this starts intentionally small (check-in streaks only)
 * rather than trying to anticipate every milestone type a real cohort
 * might respond to.
 */
class CheckInStreakMilestoneDetector implements EventSubscriberInterface
{
    private const THRESHOLDS = [3, 7, 14, 30, 60, 100];

    public function __construct(
        private readonly StreakCalculator $streaks,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [AttendanceCheckedInEvent::NAME => 'onCheckedIn'];
    }

    public function onCheckedIn(AttendanceCheckedInEvent $event): void
    {
        $member = $event->getLog()->getMember();
        $streak = $this->streaks->currentStreakDays($member, new \DateTimeImmutable('today'));

        // Fires exactly once per threshold crossing: a streak only ever
        // passes through a given value once per unbroken run (it climbs by
        // exactly 1 per consecutive day), so no separate "already
        // notified" bookkeeping is needed.
        if (in_array($streak, self::THRESHOLDS, true)) {
            $this->dispatcher->dispatch(
                new MemberMilestoneReachedEvent($member, 'checkin_streak', $streak),
                MemberMilestoneReachedEvent::NAME,
            );
        }
    }
}

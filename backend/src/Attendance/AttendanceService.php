<?php

namespace App\Attendance;

use App\Entity\AttendanceLog;
use App\Entity\MemberProfile;
use App\Enum\CheckInMethod;
use App\Enum\MembershipStatus;
use App\Enum\UserStatus;
use App\Event\AttendanceCheckedInEvent;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.3 / §8.1: verify active membership, insert
 * ATTENDANCE_LOG, emit attendance.checked_in. Also closes the Phase 4
 * deferral: check-in is blocked (with a specific reason, functional
 * requirements §4.1) when the account is suspended or the membership
 * isn't active.
 */
class AttendanceService
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function checkIn(MemberProfile $member): AttendanceLog
    {
        $user = $member->getUser();
        if ($user->getStatus() === UserStatus::SUSPENDED) {
            throw $this->blocked(CheckInBlockedReason::ACCOUNT_SUSPENDED);
        }

        $membership = $this->memberships->findMostRecentForMember($member);
        if ($membership === null) {
            throw $this->blocked(CheckInBlockedReason::NO_MEMBERSHIP);
        }

        if ($membership->markExpiredIfNeeded()) {
            $this->em->flush();
        }

        $blockedReason = match ($membership->getStatus()) {
            MembershipStatus::EXPIRED => CheckInBlockedReason::MEMBERSHIP_EXPIRED,
            MembershipStatus::PAUSED => CheckInBlockedReason::MEMBERSHIP_PAUSED,
            MembershipStatus::CANCELLED => CheckInBlockedReason::MEMBERSHIP_CANCELLED,
            MembershipStatus::ACTIVE => null,
        };

        if ($blockedReason !== null) {
            throw $this->blocked($blockedReason);
        }

        $log = new AttendanceLog($member, new \DateTimeImmutable(), CheckInMethod::MANUAL);
        $this->em->persist($log);
        $this->em->flush();

        $gym = $membership->getPlan()->getGym();
        $this->dispatcher->dispatch(new AttendanceCheckedInEvent($log, $gym), AttendanceCheckedInEvent::NAME);

        return $log;
    }

    private function blocked(CheckInBlockedReason $reason): CheckInBlockedException
    {
        return new CheckInBlockedException($reason, match ($reason) {
            CheckInBlockedReason::ACCOUNT_SUSPENDED => 'Your account has been suspended. Please contact the gym.',
            CheckInBlockedReason::NO_MEMBERSHIP => "You don't have a membership yet. Please contact the gym.",
            CheckInBlockedReason::MEMBERSHIP_EXPIRED => 'Your membership has expired. Please renew to check in.',
            CheckInBlockedReason::MEMBERSHIP_PAUSED => 'Your membership is paused. Resume it to check in.',
            CheckInBlockedReason::MEMBERSHIP_CANCELLED => 'Your membership has been cancelled. Please contact the gym.',
        });
    }
}

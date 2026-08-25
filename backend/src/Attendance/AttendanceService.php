<?php

namespace App\Attendance;

use App\Billing\CheckInEligibilityChecker;
use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Enum\CheckInMethod;
use App\Enum\MembershipStatus;
use App\Enum\UserStatus;
use App\Event\AttendanceCheckedInEvent;
use App\Event\AttendanceCheckedOutEvent;
use App\Repository\AttendanceLogRepository;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.3 / §8.1: verify active membership, insert
 * ATTENDANCE_LOG, emit attendance.checked_in. Also closes the Phase 4
 * deferral: check-in is blocked (with a specific reason, functional
 * requirements §4.1) when the account is suspended or the membership
 * isn't active. gym-management-billing-v1.md §5.5 adds billing-based
 * gating (CheckInEligibilityChecker) once the membership is confirmed
 * ACTIVE — this is the single call site both check-in controller actions
 * (self + front-desk) funnel through, so both are covered by one change.
 */
class AttendanceService
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CheckInEligibilityChecker $eligibility,
    ) {
    }

    /**
     * $method defaults to MANUAL (the Member's own self-checkin, Phase
     * 5). roadmap Phase 15.1's front-desk variant (Owner/Staff checking a
     * member in) passes CheckInMethod::FRONT_DESK explicitly — same
     * validation, same event, only the recorded method differs.
     *
     * $branch is which physical branch this check-in happened at — never
     * a restriction on the Member (architecture doc §5.2's hub model),
     * just a record of where they were. The controller resolves it
     * (BranchResolver, defaulting to the primary branch), not this
     * service — same "no Voter/business-rule change" boundary as every
     * other Phase 16 retrofit.
     */
    public function checkIn(MemberProfile $member, Branch $branch, CheckInMethod $method = CheckInMethod::MANUAL): AttendanceLog
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
            MembershipStatus::SUSPENDED => CheckInBlockedReason::SUBSCRIPTION_INACTIVE,
            MembershipStatus::ACTIVE => null,
        };

        if ($blockedReason !== null) {
            throw $this->blocked($blockedReason);
        }

        $billingBlockedReason = $this->eligibility->check($membership);
        if ($billingBlockedReason !== null) {
            throw $this->blocked($billingBlockedReason);
        }

        $log = new AttendanceLog($member, $branch, new \DateTimeImmutable(), $method);
        $this->em->persist($log);
        $this->em->flush();

        $gym = $membership->getPlan()->getGym();
        $this->dispatcher->dispatch(new AttendanceCheckedInEvent($log, $gym), AttendanceCheckedInEvent::NAME);

        return $log;
    }

    /**
     * Check-in-timer feature's minimal check-out mutation: closes the
     * member's own open session, if they have one today. No membership/
     * suspension checks here (unlike checkIn) — checking out never needs
     * gating the way starting a new visit does.
     */
    public function checkOut(MemberProfile $member): AttendanceLog
    {
        $log = $this->attendanceLogs->findOpenForMember($member);
        if ($log === null) {
            throw new NoActiveSessionException("You don't have an active check-in to check out of.");
        }

        $log->checkOut(new \DateTimeImmutable());
        $this->em->flush();

        $this->dispatcher->dispatch(new AttendanceCheckedOutEvent($log), AttendanceCheckedOutEvent::NAME);

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
            CheckInBlockedReason::SUBSCRIPTION_INACTIVE => 'Your membership has been suspended. Please contact the gym.',
            CheckInBlockedReason::ABSENT_INVOICE => 'You have a missed payment on file. Please contact the gym to settle it.',
            CheckInBlockedReason::OVERDUE => 'Your payment is overdue. Please settle it to check in.',
        });
    }
}

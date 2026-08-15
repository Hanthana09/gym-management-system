<?php

namespace App\Membership;

use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Enum\MembershipStatus;
use App\Enum\UserStatus;
use App\Event\MembershipCreatedEvent;
use App\Repository\MembershipPlanRepository;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.2: plan creation/pricing (Owner only), member
 * enrollment, pause/cancel.
 */
class MembershipService
{
    public function __construct(
        private readonly MembershipPlanRepository $plans,
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /** @param string[] $features */
    public function createPlan(Branch $branch, string $name, string $price, int $durationDays, array $features): MembershipPlan
    {
        $plan = new MembershipPlan($branch, $name, $price, $durationDays, $features);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    /**
     * @param string[]|null $features
     */
    public function updatePlan(MembershipPlan $plan, ?string $name, ?string $price, ?int $durationDays, ?array $features): void
    {
        if ($name !== null) {
            $plan->setName($name);
        }
        if ($price !== null) {
            $plan->setPrice($price);
        }
        if ($durationDays !== null) {
            $plan->setDurationDays($durationDays);
        }
        if ($features !== null) {
            $plan->setFeatures($features);
        }
        $this->em->flush();
    }

    /** functional requirements §3.1: blocked/warned rather than silently breaking existing memberships. */
    public function deletePlan(MembershipPlan $plan): void
    {
        if ($this->memberships->hasOngoingMembership($plan)) {
            throw new MembershipPlanHasOngoingMembershipsException();
        }

        $this->em->remove($plan);
        $this->em->flush();
    }

    /** roadmap Phase 16: plan management is branch-scoped — "which branch's plans am I editing," per the roadmap's own framing. */
    public function listPlansForBranch(Branch $branch): array
    {
        return $this->plans->findByBranch($branch);
    }

    public function enroll(MemberProfile $member, MembershipPlan $plan, bool $autoRenew = false): Membership
    {
        if ($member->getUser()->getStatus() !== UserStatus::ACTIVE) {
            throw new MembershipConflictException('member_not_active', 'Only an approved, active member can be enrolled.');
        }

        if ($this->memberships->findOneOngoingForMember($member) !== null) {
            throw new MembershipConflictException('already_enrolled', 'This member already has an active or paused membership.');
        }

        $startDate = new \DateTimeImmutable('today');
        $endDate = $startDate->modify('+' . $plan->getDurationDays() . ' days');

        $membership = new Membership($member, $plan, $startDate, $endDate, $autoRenew);
        $this->em->persist($membership);
        $this->em->flush();

        $this->dispatcher->dispatch(new MembershipCreatedEvent($membership), MembershipCreatedEvent::NAME);

        return $membership;
    }

    /** "My membership" — most recent regardless of status, with the lazy natural-expiry check applied first. */
    public function getMembershipForMember(MemberProfile $member): ?Membership
    {
        $membership = $this->memberships->findMostRecentForMember($member);
        if ($membership !== null && $membership->markExpiredIfNeeded()) {
            $this->em->flush();
        }

        return $membership;
    }

    public function pause(Membership $membership): void
    {
        if ($membership->getStatus() !== MembershipStatus::ACTIVE) {
            throw new MembershipConflictException('not_active', 'Only an active membership can be paused.');
        }

        $membership->pause();
        $this->em->flush();
    }

    public function resume(Membership $membership): void
    {
        if ($membership->getStatus() !== MembershipStatus::PAUSED) {
            throw new MembershipConflictException('not_paused', 'Only a paused membership can be resumed.');
        }

        $membership->resume();
        $this->em->flush();
    }

    public function cancel(Membership $membership): void
    {
        if (!$membership->isOngoing()) {
            throw new MembershipConflictException('not_ongoing', 'Only an active or paused membership can be cancelled.');
        }

        $membership->cancel();
        $this->em->flush();
    }
}

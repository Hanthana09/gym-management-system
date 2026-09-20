<?php

namespace App\Membership;

use App\Audit\AuditLogger;
use App\Billing\BillingCycleCalculator;
use App\Entity\Branch;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\MembershipStatus;
use App\Enum\UserStatus;
use App\Event\MembershipCreatedEvent;
use App\Repository\MembershipPlanRepository;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.2: plan creation/pricing (Owner only), member
 * enrollment, plan changes, pause/cancel.
 */
class MembershipService
{
    public function __construct(
        private readonly MembershipPlanRepository $plans,
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AuditLogger $auditLogger,
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

        // gym-management-billing-v1.md: every new enrollment opts into the
        // recurring billing engine going forward — see this phase's plan
        // for why this is the default rather than a request-level flag.
        $billingAnchorDay = (int) $startDate->format('j');
        $membership->enableRecurringBilling($billingAnchorDay, BillingCycleCalculator::advance($startDate, $billingAnchorDay));

        $this->em->persist($membership);
        $this->em->flush();

        $this->dispatcher->dispatch(new MembershipCreatedEvent($membership), MembershipCreatedEvent::NAME);

        return $membership;
    }

    /**
     * Owner reassigning an existing member's plan (architecture doc
     * §6.2/§9 "plan changes" — the counterpart to enroll() for a member
     * who already has an ongoing membership rather than a fresh one).
     * Same-plan is a no-op: nothing to change, nothing to audit.
     */
    public function changePlan(Membership $membership, MembershipPlan $newPlan, User $actingUser): void
    {
        if (!$membership->isOngoing()) {
            throw new MembershipConflictException('not_ongoing', 'Only an active, paused, or suspended membership can change plans.');
        }

        $previousPlan = $membership->getPlan();
        if ($previousPlan === $newPlan) {
            return;
        }

        $membership->changePlan($newPlan);
        $this->em->flush();

        $this->auditLogger->log($actingUser, 'membership.plan_changed', 'Membership', $membership->getId(), [
            'fromPlanId' => (string) $previousPlan->getId(),
            'toPlanId' => (string) $newPlan->getId(),
        ]);
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

    /** gym-management-billing-v1.md §5.4 — Owner/Staff enforcement action, stops future invoice generation. Existing PENDING/ABSENT invoices are left as-is. */
    public function suspend(Membership $membership): void
    {
        if ($membership->getStatus() !== MembershipStatus::ACTIVE) {
            throw new MembershipConflictException('not_active', 'Only an active membership can be suspended.');
        }

        $membership->suspend();
        $this->em->flush();
    }

    /**
     * §5.4 — nextBillingDate resets to today (the reactivation date), not
     * backfilled: the generation command will produce exactly one invoice
     * for the member's now-current period on its next run, nothing for
     * the months they were suspended. billingAnchorDay is left unchanged
     * (only a fresh resetBillingCycle payment changes it).
     */
    public function reactivate(Membership $membership): void
    {
        if ($membership->getStatus() !== MembershipStatus::SUSPENDED) {
            throw new MembershipConflictException('not_suspended', 'Only a suspended membership can be reactivated.');
        }

        $nextBillingDate = $membership->getBillingAnchorDay() !== null ? new \DateTimeImmutable('today') : null;
        $membership->reactivate($nextBillingDate);
        $this->em->flush();
    }
}

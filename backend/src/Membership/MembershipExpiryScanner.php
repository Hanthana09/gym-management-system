<?php

namespace App\Membership;

use App\Event\MembershipExpiredEvent;
use App\Event\MembershipExpiringEvent;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §8.3: "daily scan for memberships expiring in 7/3/1
 * days" — one event per match — plus the natural active→expired
 * transition implied by §6.2's `membership.expired` event, which the
 * sequence diagram doesn't separately diagram but the module description
 * lists. Both housekeeping tasks live here since they're both "look at
 * every active membership's end_date once a day."
 */
class MembershipExpiryScanner
{
    private const REMINDER_THRESHOLDS_DAYS = [7, 3, 1];

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /** @return array{expiring: int, expired: int} */
    public function scan(): array
    {
        $expiringCount = 0;
        foreach (self::REMINDER_THRESHOLDS_DAYS as $days) {
            foreach ($this->memberships->findActiveExpiringInDays($days) as $membership) {
                $this->dispatcher->dispatch(new MembershipExpiringEvent($membership, $days), MembershipExpiringEvent::NAME);
                ++$expiringCount;
            }
        }

        $expiredCount = 0;
        foreach ($this->memberships->findActivePastEndDate() as $membership) {
            $membership->markExpiredIfNeeded();
            $this->dispatcher->dispatch(new MembershipExpiredEvent($membership), MembershipExpiredEvent::NAME);
            ++$expiredCount;
        }
        if ($expiredCount > 0) {
            $this->em->flush();
        }

        return ['expiring' => $expiringCount, 'expired' => $expiredCount];
    }
}

<?php

namespace App\Gym;

use App\Entity\Gym;
use App\Entity\User;
use App\Repository\GymRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single-gym product (CLAUDE.md) — lazily provisions the Owner's one gym
 * the first time they need it (an invite in Phase 3, a membership plan
 * here) rather than requiring a separate "gym setup" screen not yet in
 * the roadmap. Extracted out of InvitationService once MembershipService
 * needed the exact same behavior.
 */
class GymProvisioningService
{
    public function __construct(
        private readonly GymRepository $gyms,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureGymForOwner(User $owner): Gym
    {
        $gym = $this->gyms->findOneByOwner($owner);
        if ($gym !== null) {
            return $gym;
        }

        $gym = new Gym($owner->getName() . "'s Gym", '', $owner);
        $this->em->persist($gym);
        $this->em->flush();

        return $gym;
    }
}

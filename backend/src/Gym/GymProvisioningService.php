<?php

namespace App\Gym;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\User;
use App\Repository\BranchRepository;
use App\Repository\GymRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single-gym product (CLAUDE.md) — lazily provisions the Owner's one gym
 * the first time they need it (an invite in Phase 3, a membership plan
 * here) rather than requiring a separate "gym setup" screen not yet in
 * the roadmap. Extracted out of InvitationService once MembershipService
 * needed the exact same behavior.
 *
 * roadmap Phase 16: also provisions that gym's primary Branch in the same
 * step — architecture doc §6.12's "every business starts with one
 * isPrimary branch... this isn't optional scaffolding" applies just as
 * much to a gym provisioned today as to the pre-existing ones this
 * phase's migration backfills.
 */
class GymProvisioningService
{
    public function __construct(
        private readonly GymRepository $gyms,
        private readonly BranchRepository $branches,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureGymForOwner(User $owner): Gym
    {
        $gym = $this->gyms->findOneByOwner($owner);
        if ($gym === null) {
            $gym = new Gym($owner->getName() . "'s Gym", '', $owner);
            $this->em->persist($gym);
            $this->em->flush();
        }

        $this->ensurePrimaryBranch($gym);

        return $gym;
    }

    public function ensurePrimaryBranch(Gym $gym): Branch
    {
        $branch = $this->branches->findPrimaryForGym($gym);
        if ($branch !== null) {
            return $branch;
        }

        $branch = new Branch($gym, $gym->getName(), '', null, isPrimary: true);
        $this->em->persist($branch);
        $this->em->flush();

        return $branch;
    }
}

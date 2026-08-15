<?php

namespace App\Security\Voter;

use App\Entity\Branch;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — create/edit/deactivate branches,
 * assign Coach/Staff to them (Owner only, §2's new branch-management
 * rows). No single-gym collapse needed: `Branch::getGym()` and
 * `Gym::getOwner()` are both real relations (same reasoning as GymVoter's
 * own docblock), so the doc's `$subject->getGym()->getOwner() === $user`
 * check works exactly as written.
 */
final class BranchVoter extends AppVoter
{
    const MANAGE = 'BRANCH_MANAGE'; // covers branch CRUD and assigning/unassigning Coach/Staff to it

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof Branch;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user) && $subject->getGym()->getOwner() === $user;
    }
}

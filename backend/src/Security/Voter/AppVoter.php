<?php

namespace App\Security\Voter;

use App\Entity\Branch;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Copied from architecture doc §9.1 — shared role-check helper used by
 * every Voter. `Role` in the doc is this project's `App\Enum\UserRole`
 * (named in Phase 2); the logic is otherwise unchanged.
 */
abstract class AppVoter extends Voter
{
    protected function isOwner(UserInterface $user): bool
    {
        return $user instanceof User && $user->getRole() === UserRole::OWNER;
    }

    protected function isCoach(UserInterface $user): bool
    {
        return $user instanceof User && $user->getRole() === UserRole::COACH;
    }

    /** roadmap Phase 15.1: the fourth, deliberately narrowest role (architecture doc §2). */
    protected function isStaff(UserInterface $user): bool
    {
        return $user instanceof User && $user->getRole() === UserRole::STAFF;
    }

    protected function isMember(UserInterface $user): bool
    {
        return $user instanceof User && $user->getRole() === UserRole::MEMBER;
    }

    /**
     * roadmap Phase 16 / architecture doc §9.1: every Coach/Staff-scoped
     * check from here on goes through this. Deliberately never called for
     * a Member — Members are hub-scoped, not branch-assigned (architecture
     * doc §5.2's core decision for this phase); if a future change makes
     * this get called on a Member's behalf, that's the bug, not a missing
     * branch assignment.
     */
    protected function hasAssignedBranch(User $user, Branch $branch): bool
    {
        return $user->getBranchAssignments()->exists(
            fn ($key, $assignment) => $assignment->getBranch() === $branch,
        );
    }
}

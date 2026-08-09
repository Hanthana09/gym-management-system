<?php

namespace App\Security\Voter;

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

    protected function isMember(UserInterface $user): bool
    {
        return $user instanceof User && $user->getRole() === UserRole::MEMBER;
    }
}

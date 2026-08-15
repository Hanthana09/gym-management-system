<?php

namespace App\Security\Voter;

use App\Entity\Announcement;
use App\Enum\Audience;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1, updated for roadmap Phase 16 —
 * Owner's branch-or-gym-wide choice. Still not a flat role check (the
 * Coach branch also requires `audience === OWN_CLIENTS`, not just
 * `isCoach($user)`), so a Coach can never post gym-wide, and Coach's
 * "own clients" logic stays a direct client relationship, unchanged by
 * this phase (§6.12's explicit note: not branch-mediated).
 */
final class AnnouncementVoter extends AppVoter
{
    const CREATE = 'ANNOUNCEMENT_CREATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::CREATE && $subject instanceof Announcement;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($this->isOwner($user)) {
            // branch_id null = gym-wide (all branches); set = must be one of this Owner's own branches.
            if ($subject->getBranch() !== null) {
                return $subject->getBranch()->getGym()->getOwner() === $user;
            }

            return $subject->getGym()->getOwner() === $user;
        }
        if ($this->isCoach($user)) {
            return $subject->getAudience() === Audience::OWN_CLIENTS; // scoped, not gym-wide
        }

        return false;
    }
}

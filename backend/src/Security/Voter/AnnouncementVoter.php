<?php

namespace App\Security\Voter;

use App\Entity\Announcement;
use App\Enum\Audience;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied verbatim from architecture doc §9.1 — "already written in full,
 * don't rewrite it." Owners broadcast gym-wide, Coaches broadcast to own
 * clients only — this is the one Voter in the set that isn't a flat role
 * check (the Coach branch also requires `audience === OWN_CLIENTS`, not
 * just `isCoach($user)`), so a Coach can never post gym-wide even by
 * passing the right subject type.
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
            return $subject->getGym()->getOwner() === $user; // gym-wide
        }
        if ($this->isCoach($user)) {
            return $subject->getAudience() === Audience::OWN_CLIENTS; // scoped, not gym-wide
        }

        return false;
    }
}

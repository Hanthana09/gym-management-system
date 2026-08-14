<?php

namespace App\Security\Voter;

use App\Entity\Gym;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Gym profile, plans, pricing, branding (Owner only, architecture doc §2
 * row 1). Copied verbatim from architecture doc §9.1 — unlike most other
 * Voters in this codebase, this one needs no single-gym collapse: `Gym`
 * already carries `getOwner()` directly (it's the root entity of the
 * "single gym" concept, not something scoped through it), so the doc's
 * own `$subject->getOwner() === $user` check works as written.
 *
 * roadmap Phase 15.2: first real use is `PATCH /gym/branding`, reusing
 * this Voter as-is per the doc's explicit "no new Voter needed" note.
 */
final class GymVoter extends AppVoter
{
    const MANAGE = 'GYM_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof Gym;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user) && $subject->getOwner() === $user;
    }
}

<?php

namespace App\Security\Voter;

use App\Entity\CoachProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1's CoachManagementVoter — add / edit /
 * suspend / reactivate coaches (Owner only, §2 row 5). Implemented for
 * the first time by gym-management-coach-management.md; until then only
 * its sibling StaffManagementVoter existed (see that class's docblock,
 * which explicitly noted this one "doesn't exist in this codebase yet").
 *
 * One adaptation beyond the literal copy: the doc's body reads
 * `$subject->getUser()->getGym() === $user->getGym()`, but this project
 * (single-gym product, CLAUDE.md) never gave User a getGym() — the same
 * collapse already used by StaffManagementVoter / MemberVoter's Owner
 * branch. With exactly one gym in practice, "does this coach belong to
 * the gym" collapses to "is the caller an Owner"; every CoachProfile
 * belongs to it.
 *
 * MANAGE covers PATCH /coaches/:id (profile + identity edit) and
 * PATCH /coaches/:id/status (suspend / reactivate). Creation
 * (POST /coaches) has no CoachProfile subject to vote on yet, so
 * CoachController gates it with a plain Owner role check — the same
 * pattern MemberController::create() uses for the walk-in path.
 */
final class CoachManagementVoter extends AppVoter
{
    const MANAGE = 'COACH_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof CoachProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user); // single-gym product — every coach belongs to this Owner's gym
    }
}

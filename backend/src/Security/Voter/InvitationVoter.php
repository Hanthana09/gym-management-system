<?php

namespace App\Security\Voter;

use App\Entity\Invitation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — Owner sends, only the invitee can
 * approve/decline (§2, "approve/decline own invitation" row).
 *
 * One addition beyond the literal copy: the email/phone comparisons are
 * guarded against comparing two nulls as equal. In the doc's data model
 * email/phone are always non-null, so `a === b` was already safe; this
 * project's Invitation/User made both nullable (Phase 3 — a single
 * "email or phone" destination field), which would otherwise let two
 * different phone-only users match on `null === null` and pass RESPOND
 * for an invitation neither of them owns. Same semantics, same shape,
 * just null-safe.
 */
final class InvitationVoter extends AppVoter
{
    const SEND = 'INVITATION_SEND';    // Owner only, for their own gym
    const RESPOND = 'INVITATION_RESPOND'; // Coach/Member — own invitation only

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SEND, self::RESPOND]) && $subject instanceof Invitation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::SEND) {
            return $this->isOwner($user) && $subject->getGym()->getOwner() === $user;
        }

        // RESPOND: deliberately does NOT check role — a pending invitee might not
        // be "active" as Coach/Member yet, only matched by user_id/email/phone.
        return $subject->getUser() === $user
            || ($subject->getEmail() !== null && $subject->getEmail() === $user->getEmail())
            || ($subject->getPhone() !== null && $subject->getPhone() === $user->getPhone());
    }
}

<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * gym-management-password-auth.md §5: an Owner may set/reset a password
 * for any Coach, Staff, or Member within their own Gym (all branches).
 * Coach/Staff/Member can never set or reset another user's password
 * under any circumstance — self-service only, via forgot-password/
 * reset-password/change-password, which don't go through this Voter at
 * all (they act on the caller's own account).
 *
 * Same single-gym collapse as StaffManagementVoter/MemberVoter's Owner
 * branch (see those Voters' own docblocks): this project has exactly one
 * gym, and a plain User (Coach/Staff — Member also arrives here as a
 * User, not a MemberProfile, since the subject of this action is the
 * login account itself) has no getGym() to compare against. With one gym
 * in practice, "does this user belong to the gym" collapses to "is the
 * caller an Owner."
 */
final class PasswordManagementVoter extends AppVoter
{
    const SET_PASSWORD = 'USER_SET_PASSWORD';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SET_PASSWORD && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user);
    }
}

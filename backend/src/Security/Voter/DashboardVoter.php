<?php

namespace App\Security\Voter;

use App\Entity\Gym;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * gym-management-dashboard-redesign.md Phase 3: gates each of the four
 * `/api/v1/dashboard/*` endpoints to its own role only — Owner can't
 * call /dashboard/staff, Coach can't call /dashboard/owner, etc. (§6's
 * negative test list). Subject is the Gym (mirrors ReportVoter's own
 * "does this Owner own this Gym" pattern) — which branch's numbers come
 * back is a query concern handled in the controller, same division
 * ReportController already documents for its own endpoints.
 */
final class DashboardVoter extends AppVoter
{
    const VIEW_OWNER = 'DASHBOARD_VIEW_OWNER';
    const VIEW_STAFF = 'DASHBOARD_VIEW_STAFF';
    const VIEW_COACH = 'DASHBOARD_VIEW_COACH';
    const VIEW_MEMBER = 'DASHBOARD_VIEW_MEMBER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW_OWNER, self::VIEW_STAFF, self::VIEW_COACH, self::VIEW_MEMBER], true)
            && $subject instanceof Gym;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return match ($attribute) {
            self::VIEW_OWNER => $this->isOwner($user) && $subject->getOwner() === $user,
            self::VIEW_STAFF => $this->isStaff($user),
            self::VIEW_COACH => $this->isCoach($user),
            self::VIEW_MEMBER => $this->isMember($user),
            default => false,
        };
    }
}

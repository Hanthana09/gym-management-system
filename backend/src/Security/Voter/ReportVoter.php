<?php

namespace App\Security\Voter;

use App\Entity\Gym;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — dashboard, trends, retention list,
 * forecast (VIEW), and CSV/PDF export specifically (EXPORT). Unlike
 * MemberVoter/InvoiceVoter elsewhere in this codebase, no single-gym
 * adaptation is needed here: `Gym::getOwner()` already exists as a real
 * method (§5.1's GYM entity), so `$subject->getOwner() === $user` works
 * exactly as written.
 *
 * EXPORT is kept distinct from VIEW so exports (which leave the app as a
 * file) can be audit-logged separately per §9's audit log rule, without
 * over-logging every dashboard page view — see ReportController's
 * export() action for where that audit entry is actually written.
 */
final class ReportVoter extends AppVoter
{
    const VIEW = 'REPORT_VIEW';
    const EXPORT = 'REPORT_EXPORT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EXPORT]) && $subject instanceof Gym;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $this->isOwner($user) && $subject->getOwner() === $user;
    }
}

<?php

namespace App\Security\Voter;

use App\Entity\Notification;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied verbatim from architecture doc §9.1 — "already written in full,
 * don't rewrite it." No adaptation needed: Notification::getUser() already
 * exists with a matching signature.
 */
final class NotificationVoter extends AppVoter
{
    const VIEW = 'NOTIFICATION_VIEW';
    const MARK_READ = 'NOTIFICATION_MARK_READ';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MARK_READ]) && $subject instanceof Notification;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return $subject->getUser() === $token->getUser(); // no role branch needed — always "own only"
    }
}

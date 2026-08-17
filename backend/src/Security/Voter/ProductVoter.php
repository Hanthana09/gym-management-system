<?php

namespace App\Security\Voter;

use App\Entity\Product;
use App\Entity\ProductCategory;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — manage/read the retail product
 * catalog (Phase 17). Owner-only for create/update/deactivate; Staff
 * gets read-only access to make a sale. Covers both `Product` and
 * `ProductCategory` (§9.1's `supports()` matches either).
 *
 * Single-gym collapse, same reasoning as ExpenseVoter's docblock: the
 * doc's body reads `$subject->getGym() === $user->getGym()`, but
 * `User::getGym()` doesn't exist here (CLAUDE.md) — with exactly one gym
 * in practice, "does this catalog entry belong to the Owner's gym"
 * collapses to "is this an Owner/Staff at all."
 */
final class ProductVoter extends AppVoter
{
    const MANAGE = 'PRODUCT_MANAGE'; // create/update/deactivate — Owner only
    const VIEW = 'PRODUCT_VIEW';     // Owner and Staff — read the catalog to make a sale

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::VIEW], true)
            && ($subject instanceof Product || $subject instanceof ProductCategory);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($attribute === self::MANAGE) {
            return $this->isOwner($user);
        }

        // VIEW
        return $this->isOwner($user) || $this->isStaff($user);
    }
}

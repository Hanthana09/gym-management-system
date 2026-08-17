<?php

namespace App\Security\Voter;

use App\Entity\ProductSale;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Copied from architecture doc §9.1 — record/view retail sales (Phase
 * 17). Same permission shape as `ExpenseVoter` (see that Voter's
 * docblock for the single-gym-collapse reasoning, identical here).
 */
final class ProductSaleVoter extends AppVoter
{
    const CREATE = 'PRODUCT_SALE_CREATE'; // Owner: any branch; Staff: own assigned branch(es) only
    const VIEW = 'PRODUCT_SALE_VIEW';     // Owner: any branch; Staff: own assigned branch(es) only
    const MANAGE = 'PRODUCT_SALE_MANAGE'; // update/delete — Owner only, no exceptions

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::CREATE, self::VIEW, self::MANAGE], true)
            && $subject instanceof ProductSale;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if ($this->isOwner($user)) {
            return true;
        }

        if ($this->isStaff($user) && in_array($attribute, [self::CREATE, self::VIEW], true)) {
            return $this->hasAssignedBranch($user, $subject->getBranch());
        }

        return false;
    }
}

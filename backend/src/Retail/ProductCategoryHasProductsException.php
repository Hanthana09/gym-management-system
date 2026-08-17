<?php

namespace App\Retail;

/** Same "block, don't silently orphan" rule as MembershipPlanHasOngoingMembershipsException. */
class ProductCategoryHasProductsException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This category has products in it and cannot be deleted.');
    }
}

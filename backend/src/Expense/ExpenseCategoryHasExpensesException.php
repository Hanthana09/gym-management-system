<?php

namespace App\Expense;

/** Same "block, don't silently orphan" rule as MembershipPlanHasOngoingMembershipsException. */
class ExpenseCategoryHasExpensesException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This category has expenses recorded against it and cannot be deleted.');
    }
}

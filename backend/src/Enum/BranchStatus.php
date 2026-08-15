<?php

namespace App\Enum;

/** architecture doc §5.1's BRANCH.status — matches functional requirements §14.1: a deactivated branch stops accepting new check-ins/bookings but keeps its historical data. */
enum BranchStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

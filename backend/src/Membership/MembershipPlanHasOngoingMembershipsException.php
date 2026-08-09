<?php

namespace App\Membership;

/** functional requirements §3.1: block plan deletion rather than silently breaking existing memberships. */
class MembershipPlanHasOngoingMembershipsException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This plan has active or paused memberships and cannot be deleted.');
    }
}

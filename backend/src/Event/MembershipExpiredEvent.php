<?php

namespace App\Event;

use App\Entity\Membership;
use Symfony\Contracts\EventDispatcher\Event;

/** architecture doc §6.2: "Emits ... membership.expired". */
final class MembershipExpiredEvent extends Event
{
    public const NAME = 'membership.expired';

    public function __construct(private readonly Membership $membership)
    {
    }

    public function getMembership(): Membership
    {
        return $this->membership;
    }
}

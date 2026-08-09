<?php

namespace App\Event;

use App\Entity\Membership;
use Symfony\Contracts\EventDispatcher\Event;

/** architecture doc §6.2: "Emits membership.created". */
final class MembershipCreatedEvent extends Event
{
    public const NAME = 'membership.created';

    public function __construct(private readonly Membership $membership)
    {
    }

    public function getMembership(): Membership
    {
        return $this->membership;
    }
}

<?php

namespace App\Event;

use App\Entity\Invitation;
use Symfony\Contracts\EventDispatcher\Event;

/** architecture doc §8.4: "Inv->>EB: emit invitation.approved". */
final class InvitationApprovedEvent extends Event
{
    public const NAME = 'invitation.approved';

    public function __construct(private readonly Invitation $invitation)
    {
    }

    public function getInvitation(): Invitation
    {
        return $this->invitation;
    }
}

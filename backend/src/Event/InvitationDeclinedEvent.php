<?php

namespace App\Event;

use App\Entity\Invitation;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Not explicitly named in architecture doc §8.4's diagram (which only
 * labels the approve path), but its own prose says decline "follows the
 * same shape... the Owner is notified of the decline instead" — and
 * functional requirements §2.2 requires that notification, so this event
 * exists for symmetry with InvitationApprovedEvent.
 */
final class InvitationDeclinedEvent extends Event
{
    public const NAME = 'invitation.declined';

    public function __construct(private readonly Invitation $invitation)
    {
    }

    public function getInvitation(): Invitation
    {
        return $this->invitation;
    }
}

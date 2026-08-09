<?php

namespace App\Event;

use App\Entity\Invitation;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * architecture doc §8.4: "Inv->>EB: emit invitation.sent". The
 * Notification module (Phase 7) subscribes to this on its own — nothing
 * in this phase calls it directly.
 */
final class InvitationSentEvent extends Event
{
    public const NAME = 'invitation.sent';

    public function __construct(private readonly Invitation $invitation)
    {
    }

    public function getInvitation(): Invitation
    {
        return $this->invitation;
    }
}

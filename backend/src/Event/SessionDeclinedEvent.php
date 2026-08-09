<?php

namespace App\Event;

use App\Entity\PtSession;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * architecture doc §6.4: "Coach accepts/declines → status updates, emits
 * session.confirmed / session.declined". The Notification module (Phase
 * 7) subscribes to this on its own — nothing in this phase calls it
 * directly.
 */
final class SessionDeclinedEvent extends Event
{
    public const NAME = 'session.declined';

    public function __construct(private readonly PtSession $session)
    {
    }

    public function getSession(): PtSession
    {
        return $this->session;
    }
}

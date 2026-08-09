<?php

namespace App\Event;

use App\Entity\PtSession;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * architecture doc §8.2: "T->>EB: emit session.requested". The
 * Notification module (Phase 7) subscribes to this on its own — nothing
 * in this phase calls it directly.
 */
final class SessionRequestedEvent extends Event
{
    public const NAME = 'session.requested';

    public function __construct(private readonly PtSession $session)
    {
    }

    public function getSession(): PtSession
    {
        return $this->session;
    }
}

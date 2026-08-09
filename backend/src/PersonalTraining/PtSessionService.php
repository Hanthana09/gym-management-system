<?php

namespace App\PersonalTraining;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Event\SessionConfirmedEvent;
use App\Event\SessionDeclinedEvent;
use App\Event\SessionRequestedEvent;
use App\Repository\PtSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.4 / §8.2: Member requests a session with a Coach →
 * PT_SESSION row created as pending; Coach accepts/declines → status
 * updates, emits session.confirmed / session.declined.
 */
class PtSessionService
{
    public function __construct(
        private readonly PtSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function request(MemberProfile $member, CoachProfile $coach, \DateTimeImmutable $scheduledAt, int $durationMinutes): PtSession
    {
        $session = new PtSession($coach, $member, $scheduledAt, $durationMinutes);
        $this->em->persist($session);
        $this->em->flush();

        $this->dispatcher->dispatch(new SessionRequestedEvent($session), SessionRequestedEvent::NAME);

        return $session;
    }

    public function accept(PtSession $session): void
    {
        $this->assertPending($session);

        $session->confirm();
        $this->em->flush();

        $this->dispatcher->dispatch(new SessionConfirmedEvent($session), SessionConfirmedEvent::NAME);
    }

    public function decline(PtSession $session): void
    {
        $this->assertPending($session);

        $session->decline();
        $this->em->flush();

        $this->dispatcher->dispatch(new SessionDeclinedEvent($session), SessionDeclinedEvent::NAME);
    }

    /** functional requirements §5.1: Member cancelling their own still-pending request. */
    public function cancel(PtSession $session): void
    {
        $this->assertPending($session);

        $session->cancel();
        $this->em->flush();
    }

    /**
     * functional requirements §5.3: Coach-only, no event — notes aren't a
     * notify-worthy state transition, just a record for the Coach's own
     * reference.
     */
    public function setNotes(PtSession $session, string $notes): void
    {
        $session->setNotes($notes);
        $this->em->flush();
    }

    private function assertPending(PtSession $session): void
    {
        if (!$session->isPending()) {
            throw new PtSessionConflictException('not_pending', 'This session request is no longer pending.');
        }
    }
}

<?php

namespace App\EventListener;

use App\Entity\PtSession;
use App\Event\SessionConfirmedEvent;
use App\Event\SessionDeclinedEvent;
use App\Event\SessionRequestedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Roadmap Phase 6 Definition of Done: "full booking loop ... works end to
 * end" — a Coach shouldn't have to refresh to see a new request, nor a
 * Member to see it confirmed. This is a live-sync concern of the Personal
 * Training feature itself, not the async Notification module (Phase 7,
 * which subscribes to the same domain events separately for actual
 * user-facing notifications — this listener never calls it directly).
 *
 * Same tradeoff as InvitationMercurePublisher (Phase 3): updates are
 * published non-private (no subscriber JWT required), with a minimal
 * payload (id + status only), scoped per coach and per member so each
 * side only needs to subscribe to their own topic.
 */
class PtSessionMercurePublisher implements EventSubscriberInterface
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SessionRequestedEvent::NAME => 'onSessionChanged',
            SessionConfirmedEvent::NAME => 'onSessionChanged',
            SessionDeclinedEvent::NAME => 'onSessionChanged',
        ];
    }

    public function onSessionChanged(SessionRequestedEvent|SessionConfirmedEvent|SessionDeclinedEvent $event): void
    {
        $session = $event->getSession();
        $payload = json_encode([
            'id' => (string) $session->getId(),
            'status' => $session->getStatus()->value,
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update(self::coachTopicFor($session), $payload));
        $this->hub->publish(new Update(self::memberTopicFor($session), $payload));
    }

    public static function coachTopicFor(PtSession $session): string
    {
        return sprintf('coach/%s/pt-sessions', $session->getCoach()->getUser()->getId());
    }

    public static function memberTopicFor(PtSession $session): string
    {
        return sprintf('member/%s/pt-sessions', $session->getMember()->getUser()->getId());
    }
}

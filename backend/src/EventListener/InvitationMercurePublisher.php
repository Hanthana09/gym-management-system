<?php

namespace App\EventListener;

use App\Entity\Invitation;
use App\Event\InvitationApprovedEvent;
use App\Event\InvitationDeclinedEvent;
use App\Event\InvitationSentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Roadmap Phase 3 Definition of Done: "the Owner sees a live update via
 * Mercure without refreshing." This is a live-sync concern of the
 * Invitations feature itself, not the async Notification module (Phase
 * 7, which subscribes to the same domain events separately for actual
 * user-facing notifications — this listener never calls it directly).
 *
 * Updates are published non-private (no subscriber JWT required) to keep
 * the frontend subscription simple. The payload is minimal (id + status,
 * no email/phone), so the tradeoff is: anyone who knows a gym's id can
 * observe that *some* invitation changed state, but not who to. Tightening
 * this to an authenticated subscription is a reasonable hardening task for
 * a later phase.
 */
class InvitationMercurePublisher implements EventSubscriberInterface
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvitationSentEvent::NAME => 'onInvitationChanged',
            InvitationApprovedEvent::NAME => 'onInvitationChanged',
            InvitationDeclinedEvent::NAME => 'onInvitationChanged',
        ];
    }

    public function onInvitationChanged(InvitationSentEvent|InvitationApprovedEvent|InvitationDeclinedEvent $event): void
    {
        $invitation = $event->getInvitation();

        $this->hub->publish(new Update(
            self::topicFor($invitation),
            json_encode([
                'id' => (string) $invitation->getId(),
                'status' => $invitation->getStatus()->value,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public static function topicFor(Invitation $invitation): string
    {
        return sprintf('gym/%s/invitations', $invitation->getGym()->getId());
    }
}

<?php

namespace App\EventListener;

use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Event\InvitationApprovedEvent;
use App\Event\InvitationDeclinedEvent;
use App\Event\InvitationSentEvent;
use App\Event\MembershipExpiringEvent;
use App\Event\SessionConfirmedEvent;
use App\Event\SessionDeclinedEvent;
use App\Event\SessionRequestedEvent;
use App\Notification\NotificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * architecture doc §6.6: "the Notification module never needs to know
 * *why* it's firing" — this is the one place that translates every other
 * module's domain events (Phases 2–6, already emitted, unmodified by this
 * phase) into Notification rows. Nothing here is called by those modules;
 * it only listens.
 */
class DomainEventNotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvitationSentEvent::NAME => 'onInvitationSent',
            InvitationApprovedEvent::NAME => 'onInvitationApproved',
            InvitationDeclinedEvent::NAME => 'onInvitationDeclined',
            SessionRequestedEvent::NAME => 'onSessionRequested',
            SessionConfirmedEvent::NAME => 'onSessionConfirmed',
            SessionDeclinedEvent::NAME => 'onSessionDeclined',
            MembershipExpiringEvent::NAME => 'onMembershipExpiring',
        ];
    }

    /**
     * architecture doc §6.7: only meaningful when the invitation is
     * already linked to an existing User (a re-invite) — a brand-new
     * destination has no account yet to notify until their first OTP
     * verify creates one (see InvitationService::provisionUserForDestination).
     */
    public function onInvitationSent(InvitationSentEvent $event): void
    {
        $invitation = $event->getInvitation();
        $user = $invitation->getUser();
        if ($user === null) {
            return;
        }

        $this->notifications->notify(
            $user,
            NotificationType::SYSTEM,
            'You have been invited',
            sprintf("You've been invited to join %s as a %s.", $invitation->getGym()->getName(), $invitation->getRole()->value),
            UserRole::OWNER,
        );
    }

    /** architecture doc §6.7: "invitation.approved fires so the Owner gets notified." */
    public function onInvitationApproved(InvitationApprovedEvent $event): void
    {
        $invitation = $event->getInvitation();
        $owner = $invitation->getGym()->getOwner();

        $this->notifications->notify(
            $owner,
            NotificationType::SYSTEM,
            'Invitation approved',
            sprintf('%s approved their invitation to join as %s.', $invitation->getEmail() ?? $invitation->getPhone(), $invitation->getRole()->value),
            $invitation->getRole()->toUserRole(),
        );
    }

    /** functional requirements §2.2 / architecture doc §6.7: symmetric with approval. */
    public function onInvitationDeclined(InvitationDeclinedEvent $event): void
    {
        $invitation = $event->getInvitation();
        $owner = $invitation->getGym()->getOwner();

        $this->notifications->notify(
            $owner,
            NotificationType::SYSTEM,
            'Invitation declined',
            sprintf('%s declined their invitation to join as %s.', $invitation->getEmail() ?? $invitation->getPhone(), $invitation->getRole()->value),
            $invitation->getRole()->toUserRole(),
        );
    }

    /** functional requirements §5.1: "the Coach is notified." */
    public function onSessionRequested(SessionRequestedEvent $event): void
    {
        $session = $event->getSession();
        $coachUser = $session->getCoach()->getUser();

        $this->notifications->notify(
            $coachUser,
            NotificationType::BOOKING,
            'New session request',
            sprintf('%s requested a session on %s.', $session->getMember()->getUser()->getName(), $session->getScheduledAt()->format('M j, g:i A')),
            UserRole::MEMBER,
        );
    }

    /** functional requirements §5.2: "the Member is notified." */
    public function onSessionConfirmed(SessionConfirmedEvent $event): void
    {
        $session = $event->getSession();
        $memberUser = $session->getMember()->getUser();

        $this->notifications->notify(
            $memberUser,
            NotificationType::BOOKING,
            'Session confirmed',
            sprintf('%s confirmed your session on %s.', $session->getCoach()->getUser()->getName(), $session->getScheduledAt()->format('M j, g:i A')),
            UserRole::COACH,
        );
    }

    /** functional requirements §5.2: "the Member is notified" (decline path). */
    public function onSessionDeclined(SessionDeclinedEvent $event): void
    {
        $session = $event->getSession();
        $memberUser = $session->getMember()->getUser();

        $this->notifications->notify(
            $memberUser,
            NotificationType::BOOKING,
            'Session declined',
            sprintf('%s declined your session request for %s.', $session->getCoach()->getUser()->getName(), $session->getScheduledAt()->format('M j, g:i A')),
            UserRole::COACH,
        );
    }

    /** architecture doc §8.3 / functional requirements §6.1: "my membership expiring." */
    public function onMembershipExpiring(MembershipExpiringEvent $event): void
    {
        $membership = $event->getMembership();
        $memberUser = $membership->getMember()->getUser();
        $days = $event->getDaysUntilExpiry();

        $this->notifications->notify(
            $memberUser,
            NotificationType::BILLING,
            'Membership expiring soon',
            sprintf('Your %s membership expires in %d day%s.', $membership->getPlan()->getName(), $days, $days === 1 ? '' : 's'),
            // No human actor for a scheduled reminder — sourceRole stays null.
        );
    }
}

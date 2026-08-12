<?php

namespace App\EventListener;

use App\Billing\BillingService;
use App\Event\MembershipCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * architecture doc §6.9: "A Membership enrollment creates an INVOICE with
 * status = pending." Listens to the existing, unmodified
 * membership.created event (Phase 4) rather than MembershipService
 * calling Billing directly — same decoupling CLAUDE.md's "Events"
 * convention already uses for Notification, applied here for the first
 * time to a non-notification side effect.
 */
class MembershipInvoiceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BillingService $billing)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [MembershipCreatedEvent::NAME => 'onMembershipCreated'];
    }

    public function onMembershipCreated(MembershipCreatedEvent $event): void
    {
        $this->billing->issueInvoiceForMembership($event->getMembership());
    }
}

<?php

namespace App\Event;

use App\Entity\MemberProfile;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * roadmap Phase 9.3 (GTM Pillar D). Dispatched via the same
 * EventDispatcher every other domain event in this codebase uses — the
 * Notification module (Phase 7) picks it up the same way it picks up
 * every other event, via a brand-new listener that is not part of Phase
 * 7's own files (see MilestoneNotificationSubscriber).
 */
final class MemberMilestoneReachedEvent extends Event
{
    public const NAME = 'member.milestone_reached';

    public function __construct(
        private readonly MemberProfile $member,
        private readonly string $milestoneType,
        private readonly int $value,
    ) {
    }

    public function getMember(): MemberProfile
    {
        return $this->member;
    }

    /** e.g. 'checkin_streak' — the roadmap's "keep the initial milestone set small," so this is the only type today. */
    public function getMilestoneType(): string
    {
        return $this->milestoneType;
    }

    /** e.g. 7 (days), for a checkin_streak milestone. */
    public function getValue(): int
    {
        return $this->value;
    }
}

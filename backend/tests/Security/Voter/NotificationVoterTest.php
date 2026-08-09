<?php

namespace App\Tests\Security\Voter;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\NotificationVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * NotificationVoter is copied verbatim from architecture doc §9.1 — this
 * test proves the copy behaves as documented, not that the logic itself
 * needed writing.
 */
final class NotificationVoterTest extends TestCase
{
    private NotificationVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new NotificationVoter();
    }

    private function user(UserRole $role): User
    {
        static $counter = 0;
        ++$counter;

        return new User("User {$counter}", "user{$counter}@example.com", "+1555000{$counter}", $role, UserStatus::ACTIVE);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    // ---- VIEW ---------------------------------------------------------

    public function test_owner_of_the_notification_can_view_it(): void
    {
        $owner = $this->user(UserRole::MEMBER);
        $notification = new Notification($owner, 'Title', 'Body', NotificationType::SYSTEM);

        $result = $this->voter->vote($this->tokenFor($owner), $notification, [NotificationVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_user_cannot_view_someone_elses_notification_403(): void
    {
        $owner = $this->user(UserRole::MEMBER);
        $notification = new Notification($owner, 'Title', 'Body', NotificationType::SYSTEM);
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $notification, [NotificationVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- MARK_READ ------------------------------------------------------

    public function test_owner_of_the_notification_can_mark_it_read(): void
    {
        $owner = $this->user(UserRole::COACH);
        $notification = new Notification($owner, 'Title', 'Body', NotificationType::BOOKING);

        $result = $this->voter->vote($this->tokenFor($owner), $notification, [NotificationVoter::MARK_READ]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_user_cannot_mark_someone_elses_notification_read_403(): void
    {
        $owner = $this->user(UserRole::COACH);
        $notification = new Notification($owner, 'Title', 'Body', NotificationType::BOOKING);
        $someoneElse = $this->user(UserRole::OWNER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $notification, [NotificationVoter::MARK_READ]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

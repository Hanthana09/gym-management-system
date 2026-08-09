<?php

namespace App\Tests\Security\Voter;

use App\Entity\Announcement;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\Audience;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\AnnouncementVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * AnnouncementVoter is copied verbatim from architecture doc §9.1 — this
 * is the one Voter in the set that isn't a flat role check (the Coach
 * branch also requires audience === OWN_CLIENTS), so the "Coach attempts
 * gym-wide" 403 case matters more here than a generic role-mismatch test.
 */
final class AnnouncementVoterTest extends TestCase
{
    private AnnouncementVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new AnnouncementVoter();
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

    public function test_owner_can_post_a_gym_wide_announcement(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Gym', 'Address', $owner);
        $announcement = new Announcement($gym, $owner, 'Body', Audience::GYM_WIDE);

        $result = $this->voter->vote($this->tokenFor($owner), $announcement, [AnnouncementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_coach_can_post_an_own_clients_announcement(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Gym', 'Address', $owner);
        $coach = $this->user(UserRole::COACH);
        $announcement = new Announcement($gym, $coach, 'Body', Audience::OWN_CLIENTS);

        $result = $this->voter->vote($this->tokenFor($coach), $announcement, [AnnouncementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * The specific case the task calls out: a Coach must never be able to
     * post gym-wide, even though they can post CREATE-type announcements
     * at all (own_clients). This is what makes AnnouncementVoter not a
     * flat role check.
     */
    public function test_coach_attempting_gym_wide_announcement_is_denied_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Gym', 'Address', $owner);
        $coach = $this->user(UserRole::COACH);
        $announcement = new Announcement($gym, $coach, 'Body', Audience::GYM_WIDE);

        $result = $this->voter->vote($this->tokenFor($coach), $announcement, [AnnouncementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_a_different_owner_cannot_post_to_someone_elses_gym_403(): void
    {
        $realOwner = $this->user(UserRole::OWNER);
        $gym = new Gym('Gym', 'Address', $realOwner);
        $otherOwner = $this->user(UserRole::OWNER);
        $announcement = new Announcement($gym, $otherOwner, 'Body', Audience::GYM_WIDE);

        $result = $this->voter->vote($this->tokenFor($otherOwner), $announcement, [AnnouncementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_member_can_never_post_an_announcement_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Gym', 'Address', $owner);
        $member = $this->user(UserRole::MEMBER);
        $announcement = new Announcement($gym, $member, 'Body', Audience::OWN_CLIENTS);

        $result = $this->voter->vote($this->tokenFor($member), $announcement, [AnnouncementVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

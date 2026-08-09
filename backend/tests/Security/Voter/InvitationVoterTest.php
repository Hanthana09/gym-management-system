<?php

namespace App\Tests\Security\Voter;

use App\Entity\Gym;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\InvitationRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\InvitationVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * This is a pure unit test — no HTTP, no database — of the Voter copied
 * from architecture doc §9.1.
 */
final class InvitationVoterTest extends TestCase
{
    private InvitationVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new InvitationVoter();
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

    public function test_owner_can_send_invitation_for_their_own_gym(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $invitation = new Invitation($gym, $owner, null, 'invitee@example.com', null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));

        $result = $this->voter->vote($this->tokenFor($owner), $invitation, [InvitationVoter::SEND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_non_owner_cannot_send_invitation_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $coach = $this->user(UserRole::COACH);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $invitation = new Invitation($gym, $owner, null, 'invitee@example.com', null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));

        // A Coach (not the Owner) tries to send an invitation for this gym.
        $result = $this->voter->vote($this->tokenFor($coach), $invitation, [InvitationVoter::SEND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_different_owner_cannot_send_invitation_for_someone_elses_gym_403(): void
    {
        $ownerA = $this->user(UserRole::OWNER);
        $ownerB = $this->user(UserRole::OWNER);
        $gymA = new Gym("A's Gym", '1 A St', $ownerA);
        $invitation = new Invitation($gymA, $ownerA, null, 'invitee@example.com', null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));

        $result = $this->voter->vote($this->tokenFor($ownerB), $invitation, [InvitationVoter::SEND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_invitee_can_respond_to_their_own_invitation_matched_by_account(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $invitee = $this->user(UserRole::MEMBER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $invitation = new Invitation($gym, $owner, $invitee, $invitee->getEmail(), null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));

        $result = $this->voter->vote($this->tokenFor($invitee), $invitation, [InvitationVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_invitee_can_respond_when_matched_by_email_before_account_was_linked(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        // No user linked yet — architecture doc §9.1: "a pending invitee
        // might not be active yet, only matched by user_id/email/phone."
        $invitation = new Invitation($gym, $owner, null, 'future-invitee@example.com', null, InvitationRole::COACH, new \DateTimeImmutable('+7 days'));

        $invitee = new User('Future Invitee', 'future-invitee@example.com', '+15559999999', UserRole::COACH, UserStatus::PENDING_APPROVAL);

        $result = $this->voter->vote($this->tokenFor($invitee), $invitation, [InvitationVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * functional requirements §2.2: "Given I try to approve/decline an
     * invitation that isn't mine ... I get a permission error — this must
     * hold even if I somehow have the invitation's ID." This is that case.
     */
    public function test_a_different_user_cannot_respond_to_someone_elses_invitation_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $actualInvitee = $this->user(UserRole::MEMBER);
        $someoneElse = $this->user(UserRole::MEMBER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $invitation = new Invitation($gym, $owner, $actualInvitee, $actualInvitee->getEmail(), null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));

        $result = $this->voter->vote($this->tokenFor($someoneElse), $invitation, [InvitationVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * Guards the null-safety fix documented on the Voter: two different
     * phone-only (or email-only) users must never match each other's
     * invitation just because both happen to have a null email.
     */
    public function test_two_users_with_null_email_do_not_accidentally_match_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        // Invitation sent by phone only — email is null.
        $invitation = new Invitation($gym, $owner, null, null, '+15551110000', InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));

        // An unrelated phone-only user whose email is also null.
        $unrelatedUser = new User('Unrelated', null, '+15552220000', UserRole::MEMBER, UserStatus::ACTIVE);

        $result = $this->voter->vote($this->tokenFor($unrelatedUser), $invitation, [InvitationVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

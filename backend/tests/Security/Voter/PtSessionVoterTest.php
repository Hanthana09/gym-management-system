<?php

namespace App\Tests\Security\Voter;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\PtSessionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * PtSessionVoter is copied verbatim from architecture doc §9.1 — this
 * test proves the copy behaves as documented, not that the logic itself
 * needed writing.
 */
final class PtSessionVoterTest extends TestCase
{
    private PtSessionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PtSessionVoter();
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

    private function session(): array
    {
        $coachUser = $this->user(UserRole::COACH);
        $memberUser = $this->user(UserRole::MEMBER);
        $session = new PtSession(new CoachProfile($coachUser), new MemberProfile($memberUser), new \DateTimeImmutable('+1 day'), 60);

        return [$session, $coachUser, $memberUser];
    }

    // ---- REQUEST (Member, for self) ----------------------------------------

    public function test_member_can_request_a_session_for_themselves(): void
    {
        [$session, , $memberUser] = $this->session();

        $result = $this->voter->vote($this->tokenFor($memberUser), $session, [PtSessionVoter::REQUEST]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_different_member_cannot_request_on_someone_elses_behalf_403(): void
    {
        [$session] = $this->session();
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $session, [PtSessionVoter::REQUEST]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- RESPOND (Coach, own sessions only) --------------------------------

    public function test_coach_can_respond_to_their_own_session(): void
    {
        [$session, $coachUser] = $this->session();

        $result = $this->voter->vote($this->tokenFor($coachUser), $session, [PtSessionVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** functional requirements §5.2: "Given I try to respond to a request assigned to a different Coach... permission error." */
    public function test_a_different_coach_cannot_respond_to_this_session_403(): void
    {
        [$session] = $this->session();
        $otherCoach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($otherCoach), $session, [PtSessionVoter::RESPOND]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- VIEW (Owner: any; Coach/Member: own) ------------------------------

    public function test_owner_can_view_any_session(): void
    {
        [$session] = $this->session();
        $owner = $this->user(UserRole::OWNER);

        $result = $this->voter->vote($this->tokenFor($owner), $session, [PtSessionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_the_owning_coach_can_view_their_own_session(): void
    {
        [$session, $coachUser] = $this->session();

        $result = $this->voter->vote($this->tokenFor($coachUser), $session, [PtSessionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_the_booking_member_can_view_their_own_session(): void
    {
        [$session, , $memberUser] = $this->session();

        $result = $this->voter->vote($this->tokenFor($memberUser), $session, [PtSessionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_a_member_cannot_view_someone_elses_session_403(): void
    {
        [$session] = $this->session();
        $someoneElse = $this->user(UserRole::MEMBER);

        $result = $this->voter->vote($this->tokenFor($someoneElse), $session, [PtSessionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_a_coach_not_assigned_to_the_session_cannot_view_it_403(): void
    {
        [$session] = $this->session();
        $otherCoach = $this->user(UserRole::COACH);

        $result = $this->voter->vote($this->tokenFor($otherCoach), $session, [PtSessionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

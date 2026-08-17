<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\SessionConfirmedEvent;
use App\Event\SessionDeclinedEvent;
use App\Event\SessionRequestedEvent;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers functional requirements §5.1 (Member requests a session,
 * including cancelling a still-pending one), §5.2 (Coach accepts/declines,
 * including the cross-Coach permission error), and §5.3 (session notes
 * stay invisible to the Member by default — the open Coach-visibility
 * question flagged in architecture doc §9 is left exactly as-is; this
 * only proves the current default holds).
 */
final class PtSessionControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE pt_session, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(
        string $name,
        string $email,
        UserRole $role = UserRole::MEMBER,
        UserStatus $status = UserStatus::ACTIVE,
    ): User {
        $user = new User($name, $email, null, $role, $status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER);
        $this->em->persist(new MemberProfile($user));
        $this->em->flush();

        return $user;
    }

    /** roadmap Phase 16: PtSessionVoter::RESPOND now requires the Coach be assigned to the session's branch — every coach this test creates is assigned to the (single, primary) branch, matching the single-branch regression case. */
    private function createCoach(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::COACH);
        $this->em->persist(new CoachProfile($user));
        $this->em->persist(new BranchAssignment($user, $this->primaryBranch()));
        $this->em->flush();

        return $user;
    }

    private function primaryBranch(): Branch
    {
        $gym = $this->em->getRepository(Gym::class)->findOneBy([]);
        if ($gym === null) {
            $owner = $this->createUser('Olivia Owner', 'owner-' . bin2hex(random_bytes(4)) . '@example.com', UserRole::OWNER);
            $gym = new Gym("Olivia's Gym", '', $owner);
            $this->em->persist($gym);
        }

        $branch = $this->em->getRepository(Branch::class)->findOneBy(['gym' => $gym, 'isPrimary' => true]);
        if ($branch === null) {
            $branch = new Branch($gym, 'Main', '', isPrimary: true);
            $this->em->persist($branch);
        }
        $this->em->flush();

        return $branch;
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            '/api' . $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($actingAs),
            ],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function requestSession(User $member, User $coach, string $scheduledAt = '+1 day', int $durationMinutes = 60): array
    {
        return $this->request('POST', '/pt-sessions', $member, [
            'coachUserId' => (string) $coach->getId(),
            'scheduledAt' => (new \DateTimeImmutable($scheduledAt))->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $durationMinutes,
        ]);
    }

    // ---- §5.1 Member requests a session -------------------------------------

    public function test_given_valid_request_when_created_then_pending_and_event_dispatched(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            SessionRequestedEvent::NAME,
            function (SessionRequestedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->requestSession($member, $coach);

        self::assertSame(201, $result['status']);
        self::assertSame('pending', $result['body']['status']);
        self::assertSame($coach->getName(), $result['body']['coach']['name']);
        self::assertCount(1, $dispatched);
    }

    public function test_given_pending_request_when_member_views_own_sessions_then_it_shows_pending(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $this->requestSession($member, $coach);

        $result = $this->request('GET', '/pt-sessions/me', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['sessions']);
        self::assertSame('pending', $result['body']['sessions'][0]['status']);
    }

    public function test_given_coach_when_pending_request_created_then_it_appears_in_their_schedule(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $this->requestSession($member, $coach);

        $result = $this->request('GET', "/coaches/{$coach->getId()}/schedule", $coach);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['sessions']);
        self::assertSame($member->getName(), $result['body']['sessions'][0]['member']['name']);
    }

    /** functional requirements §5.1: "Given the Coach hasn't responded, when I view my pending request, then I can cancel it before it's accepted." */
    public function test_given_still_pending_when_member_cancels_then_status_cancelled(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $member, ['status' => 'cancelled']);

        self::assertSame(200, $result['status']);
        self::assertSame('cancelled', $result['body']['status']);
    }

    public function test_a_different_member_cannot_cancel_someone_elses_request_403(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $someoneElse = $this->createMember('Someone Else', 'else@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $someoneElse, ['status' => 'cancelled']);

        self::assertSame(403, $result['status']);
    }

    public function test_given_already_confirmed_when_member_tries_to_cancel_then_conflict(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);
        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $member, ['status' => 'cancelled']);

        self::assertSame(409, $result['status']);
        self::assertSame('not_pending', $result['body']['error']);
    }

    // ---- §5.2 Coach responds -------------------------------------------------

    public function test_given_pending_request_when_coach_accepts_then_confirmed_and_event_dispatched(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            SessionConfirmedEvent::NAME,
            function (SessionConfirmedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);

        self::assertSame(200, $result['status']);
        self::assertSame('confirmed', $result['body']['status']);
        self::assertCount(1, $dispatched);
    }

    public function test_given_pending_request_when_coach_declines_then_declined_and_event_dispatched_and_member_notified(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            SessionDeclinedEvent::NAME,
            function (SessionDeclinedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'declined']);

        self::assertSame(200, $result['status']);
        self::assertSame('declined', $result['body']['status']);
        self::assertCount(1, $dispatched);

        // "the slot is freed" — proven by the Member being able to request a new one for the same time with the same coach.
        $again = $this->requestSession($member, $coach);
        self::assertSame(201, $again['status']);
    }

    /** functional requirements §5.2: "Given I try to respond to a request assigned to a different Coach, when I attempt it, then I get a permission error." */
    public function test_a_different_coach_cannot_respond_to_this_session_403(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $otherCoach = $this->createCoach('Priya Coach', 'priya@example.com');
        $created = $this->requestSession($member, $coach);

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $otherCoach, ['status' => 'confirmed']);

        self::assertSame(403, $result['status']);
    }

    public function test_given_already_declined_when_accept_attempted_then_conflict(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);
        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'declined']);

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);

        self::assertSame(409, $result['status']);
        self::assertSame('not_pending', $result['body']['error']);
    }

    // ---- §5.3 Session notes --------------------------------------------------

    public function test_given_coach_adds_notes_when_coach_views_own_session_then_notes_visible(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);
        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);

        $noted = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/notes", $coach, ['notes' => 'Great form on squats today.']);
        self::assertSame(200, $noted['status']);
        self::assertSame('Great form on squats today.', $noted['body']['notes']);

        $schedule = $this->request('GET', "/coaches/{$coach->getId()}/schedule", $coach);
        self::assertSame('Great form on squats today.', $schedule['body']['sessions'][0]['notes']);
    }

    /**
     * functional requirements §5.3: "only I (and not the Member, by
     * default) can see them." This is the specific guarantee the task
     * asked to verify without building any Member-facing view of notes.
     */
    public function test_given_coach_adds_notes_when_member_views_own_sessions_then_notes_are_null(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $created = $this->requestSession($member, $coach);
        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/status", $coach, ['status' => 'confirmed']);
        $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/notes", $coach, ['notes' => 'Private coaching notes.']);

        $result = $this->request('GET', '/pt-sessions/me', $member);

        self::assertSame(200, $result['status']);
        self::assertNull($result['body']['sessions'][0]['notes']);
    }

    public function test_a_different_coach_cannot_add_notes_to_this_session_403(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $coach = $this->createCoach('Carlos Coach', 'coach@example.com');
        $otherCoach = $this->createCoach('Priya Coach', 'priya@example.com');
        $created = $this->requestSession($member, $coach);

        $result = $this->request('PATCH', "/pt-sessions/{$created['body']['id']}/notes", $otherCoach, ['notes' => 'Sneaky notes.']);

        self::assertSame(403, $result['status']);
    }

    // ---- roadmap Phase 16 / functional requirements §14.3: coach picker is branch-filterable ----

    public function test_given_two_branches_when_coaches_listed_for_one_then_only_that_branchs_coach_returned(): void
    {
        $primary = $this->primaryBranch();
        $gym = $primary->getGym();
        $owner = $gym->getOwner();
        $downtown = new Branch($gym, 'Downtown', '1 Main St');
        $this->em->persist($downtown);
        $this->em->flush();

        $primaryCoach = $this->createCoach('Carlos Coach', 'carlos@example.com');
        $downtownCoachUser = $this->createUser('Priya Coach', 'priya@example.com', UserRole::COACH);
        $this->em->persist(new CoachProfile($downtownCoachUser));
        $this->em->persist(new BranchAssignment($downtownCoachUser, $downtown));
        $this->em->flush();

        $atDowntown = $this->request('GET', '/coaches?branchId=' . $downtown->getId(), $owner);
        self::assertSame(200, $atDowntown['status']);
        self::assertSame([$downtownCoachUser->getName()], array_column($atDowntown['body']['coaches'], 'name'));

        $atPrimary = $this->request('GET', '/coaches?branchId=' . $primary->getId(), $owner);
        self::assertSame([$primaryCoach->getName()], array_column($atPrimary['body']['coaches'], 'name'));
    }

    public function test_omitting_branch_id_on_coaches_defaults_to_the_primary_branch(): void
    {
        $primary = $this->primaryBranch();
        $gym = $primary->getGym();
        $owner = $gym->getOwner();
        $downtown = new Branch($gym, 'Downtown', '1 Main St');
        $this->em->persist($downtown);
        $this->em->flush();

        $primaryCoach = $this->createCoach('Carlos Coach', 'carlos@example.com');
        $downtownCoachUser = $this->createUser('Priya Coach', 'priya@example.com', UserRole::COACH);
        $this->em->persist(new CoachProfile($downtownCoachUser));
        $this->em->persist(new BranchAssignment($downtownCoachUser, $downtown));
        $this->em->flush();

        $result = $this->request('GET', '/coaches', $owner);

        self::assertSame([$primaryCoach->getName()], array_column($result['body']['coaches'], 'name'));
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\Invitation;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Entity\User;
use App\Enum\InvitationRole;
use App\Enum\InvitationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers functional requirements §6.2 (Owner gym-wide broadcast) and §6.3
 * (Coach own-clients broadcast), including the gym-scoping guarantee the
 * task calls out explicitly even though this is a single-gym product
 * today — "relevant if the multi-gym extensibility in the data model is
 * ever exercised."
 */
final class AnnouncementControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE notification, announcement, pt_session, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(string $name, string $email, UserRole $role): User
    {
        $user = new User($name, $email, null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createOwnerWithGym(string $name, string $email): array
    {
        $owner = $this->createUser($name, $email, UserRole::OWNER);
        $gym = new Gym($name . "'s Gym", '', $owner);
        $this->em->persist($gym);
        $this->em->flush();

        return [$owner, $gym];
    }

    /** Approved invitation is the only thing that links a Coach/Member to a specific gym (see InvitationRepository::findApprovedUsersForGym). */
    private function approveInto(Gym $gym, User $owner, User $user, InvitationRole $role): void
    {
        $invitation = new Invitation($gym, $owner, $user, $user->getEmail(), null, $role, new \DateTimeImmutable('+7 days'));
        $invitation->approve();
        $this->em->persist($invitation);

        if ($role === InvitationRole::COACH && $this->em->getRepository(CoachProfile::class)->findOneBy(['user' => $user]) === null) {
            $this->em->persist(new CoachProfile($user));
        }
        if ($role === InvitationRole::MEMBER && $this->em->getRepository(MemberProfile::class)->findOneBy(['user' => $user]) === null) {
            $this->em->persist(new MemberProfile($user));
        }
        $this->em->flush();
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            $uri,
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

    private function notificationsFor(User $user): array
    {
        return $this->request('GET', '/notifications', $user)['body']['notifications'];
    }

    // ---- §6.2 Owner announcements -------------------------------------

    public function test_given_owner_publishes_gym_wide_when_sent_then_every_active_member_and_coach_notified(): void
    {
        [$owner, $gym] = $this->createOwnerWithGym('Olivia Owner', 'owner@example.com');
        $memberA = $this->createUser('Member A', 'membera@example.com', UserRole::MEMBER);
        $memberB = $this->createUser('Member B', 'memberb@example.com', UserRole::MEMBER);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $this->approveInto($gym, $owner, $memberA, InvitationRole::MEMBER);
        $this->approveInto($gym, $owner, $memberB, InvitationRole::MEMBER);
        $this->approveInto($gym, $owner, $coach, InvitationRole::COACH);

        $result = $this->request('POST', '/announcements', $owner, ['body' => 'Gym closed Monday.', 'audience' => 'gym_wide']);

        self::assertSame(201, $result['status']);
        self::assertSame(3, $result['body']['recipientCount']);

        foreach ([$memberA, $memberB, $coach] as $recipient) {
            $notifications = $this->notificationsFor($recipient);
            self::assertCount(1, $notifications);
            self::assertSame('announcement', $notifications[0]['type']);
            self::assertSame('owner', $notifications[0]['sourceRole']);
            self::assertSame('Gym closed Monday.', $notifications[0]['body']);
        }

        // The Owner doesn't need to be notified of their own broadcast.
        self::assertCount(0, $this->notificationsFor($owner));
    }

    public function test_member_can_never_post_an_announcement_403(): void
    {
        [$owner, $gym] = $this->createOwnerWithGym('Olivia Owner', 'owner@example.com');
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $this->approveInto($gym, $owner, $member, InvitationRole::MEMBER);

        $result = $this->request('POST', '/announcements', $member, ['body' => 'Hi', 'audience' => 'gym_wide']);

        self::assertSame(403, $result['status']);
    }

    // ---- §6.3 Coach announcements --------------------------------------

    public function test_given_coach_publishes_to_own_clients_when_sent_then_only_those_clients_notified(): void
    {
        [$owner, $gym] = $this->createOwnerWithGym('Olivia Owner', 'owner@example.com');
        $coachA = $this->createUser('Coach A', 'coacha@example.com', UserRole::COACH);
        $coachB = $this->createUser('Coach B', 'coachb@example.com', UserRole::COACH);
        $clientOfA = $this->createUser('Client Of A', 'clienta@example.com', UserRole::MEMBER);
        $unrelatedMember = $this->createUser('Unrelated Member', 'unrelated@example.com', UserRole::MEMBER);
        $this->approveInto($gym, $owner, $coachA, InvitationRole::COACH);
        $this->approveInto($gym, $owner, $coachB, InvitationRole::COACH);
        $this->approveInto($gym, $owner, $clientOfA, InvitationRole::MEMBER);
        $this->approveInto($gym, $owner, $unrelatedMember, InvitationRole::MEMBER);

        // "Client of A" is established the same way Phase 6 does: a PT session with that coach.
        $coachAProfile = $this->em->getRepository(CoachProfile::class)->findOneBy(['user' => $coachA]);
        $clientProfile = $this->em->getRepository(MemberProfile::class)->findOneBy(['user' => $clientOfA]);
        $this->em->persist(new PtSession($coachAProfile, $clientProfile, new \DateTimeImmutable('+1 day'), 60));
        $this->em->flush();

        $result = $this->request('POST', '/announcements', $coachA, ['body' => 'New PT slots open.', 'audience' => 'own_clients']);

        self::assertSame(201, $result['status']);
        self::assertSame(1, $result['body']['recipientCount']);

        $clientNotifications = $this->notificationsFor($clientOfA);
        self::assertCount(1, $clientNotifications);
        self::assertSame('coach', $clientNotifications[0]['sourceRole']);

        // functional requirements §6.3: "only my assigned clients receive it, not the whole gym."
        self::assertCount(0, $this->notificationsFor($unrelatedMember));
        self::assertCount(0, $this->notificationsFor($coachB));
    }

    /** Companion HTTP-level test to AnnouncementVoterTest's unit case. */
    public function test_coach_attempting_gym_wide_announcement_returns_403(): void
    {
        [$owner, $gym] = $this->createOwnerWithGym('Olivia Owner', 'owner@example.com');
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $this->approveInto($gym, $owner, $coach, InvitationRole::COACH);

        $result = $this->request('POST', '/announcements', $coach, ['body' => 'Hi everyone', 'audience' => 'gym_wide']);

        self::assertSame(403, $result['status']);
    }

    // ---- Gym scoping ----------------------------------------------------

    /**
     * functional requirements §6.2: "people at other gyms never see it."
     * The task calls this out explicitly even for a single-gym product —
     * this proves AnnouncementService resolves recipients through
     * Invitation.gym (the only place a User is linked to a specific gym),
     * not a system-wide "every active Coach/Member" query.
     */
    public function test_an_announcement_from_one_gym_never_reaches_another_gyms_people(): void
    {
        [$ownerA, $gymA] = $this->createOwnerWithGym('Owner A', 'ownera@example.com');
        [$ownerB, $gymB] = $this->createOwnerWithGym('Owner B', 'ownerb@example.com');
        $memberA = $this->createUser('Member of Gym A', 'membera@example.com', UserRole::MEMBER);
        $memberB = $this->createUser('Member of Gym B', 'memberb@example.com', UserRole::MEMBER);
        $this->approveInto($gymA, $ownerA, $memberA, InvitationRole::MEMBER);
        $this->approveInto($gymB, $ownerB, $memberB, InvitationRole::MEMBER);

        $result = $this->request('POST', '/announcements', $ownerA, ['body' => 'Gym A news', 'audience' => 'gym_wide']);

        self::assertSame(201, $result['status']);
        self::assertSame(1, $result['body']['recipientCount']);
        self::assertCount(1, $this->notificationsFor($memberA));
        self::assertCount(0, $this->notificationsFor($memberB));
        self::assertCount(0, $this->notificationsFor($ownerB));
    }
}

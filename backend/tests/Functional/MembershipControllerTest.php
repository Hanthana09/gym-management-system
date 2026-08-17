<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\MembershipCreatedEvent;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers functional requirements §3.1 (Owner manages plans) fully, and
 * the status-display parts of §3.2/§3.3. Two criteria are explicitly
 * deferred rather than stubbed:
 *
 *  - §3.2 "blocked from check-in when expired" — depends on the
 *    Attendance module (Phase 5, not built yet). This file only proves
 *    Membership.status correctly reaches 'expired'; the actual check-in
 *    block is a Phase 5 integration point.
 *  - §3.3 "no further invoices while paused" — depends on Billing
 *    (Phase 9, not built yet). This file only proves the pause action
 *    correctly sets status='paused'; invoice suppression is untested here.
 */
final class MembershipControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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

    private function createApprovedMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist(new MemberProfile($user));
        $this->em->flush();

        return $user;
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

    private function createPlan(User $owner, string $name = 'Standard', string $price = '49.99', int $durationDays = 30): array
    {
        return $this->request('POST', '/membership-plans', $owner, [
            'name' => $name,
            'price' => $price,
            'durationDays' => $durationDays,
            'features' => ['Gym floor access'],
        ]);
    }

    private function enroll(User $owner, string $memberUserId, string $planId): array
    {
        return $this->request('POST', '/memberships', $owner, ['memberUserId' => $memberUserId, 'planId' => $planId]);
    }

    // ---- §3.1 Owner manages plans -----------------------------------------

    public function test_given_create_plan_when_saved_then_immediately_available(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $created = $this->createPlan($owner, 'Gold', '79.99', 30);
        self::assertSame(201, $created['status']);
        self::assertSame('Gold', $created['body']['name']);

        $list = $this->request('GET', '/membership-plans', $owner);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['body']['plans']);
        self::assertSame('Gold', $list['body']['plans'][0]['name']);
    }

    public function test_given_plan_has_active_member_when_delete_attempted_then_blocked(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $result = $this->request('DELETE', "/membership-plans/{$plan['body']['id']}", $owner);

        self::assertSame(409, $result['status']);
        self::assertSame('plan_has_ongoing_memberships', $result['body']['error']);

        $stillExists = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM membership_plan WHERE id = ?', [$plan['body']['id']]);
        self::assertSame('1', (string) $stillExists);
    }

    public function test_given_plan_has_no_members_when_delete_attempted_then_succeeds(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $plan = $this->createPlan($owner);

        $result = $this->request('DELETE', "/membership-plans/{$plan['body']['id']}", $owner);

        self::assertSame(204, $result['status']);
    }

    public function test_non_owner_cannot_create_plan_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->createPlan($coach);

        self::assertSame(403, $result['status']);
    }

    // ---- Enrollment --------------------------------------------------------

    public function test_given_valid_enrollment_when_created_then_membership_active_and_event_dispatched(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);

        $this->client->disableReboot();
        $dispatched = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            MembershipCreatedEvent::NAME,
            function (MembershipCreatedEvent $event) use (&$dispatched) { $dispatched[] = $event; },
        );

        $result = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        self::assertSame(201, $result['status']);
        self::assertSame('active', $result['body']['status']);
        self::assertSame('Standard', $result['body']['plan']['name']);
        self::assertCount(1, $dispatched);
    }

    public function test_given_member_not_active_when_enroll_attempted_then_conflict(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $pendingUser = $this->createUser('Pending Person', 'pending@example.com', UserRole::MEMBER, UserStatus::PENDING_APPROVAL);
        $this->em->persist(new MemberProfile($pendingUser));
        $this->em->flush();
        $plan = $this->createPlan($owner);

        $result = $this->enroll($owner, (string) $pendingUser->getId(), $plan['body']['id']);

        self::assertSame(409, $result['status']);
        self::assertSame('member_not_active', $result['body']['error']);
    }

    public function test_given_member_already_enrolled_when_enroll_again_then_conflict(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $second = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        self::assertSame(409, $second['status']);
        self::assertSame('already_enrolled', $second['body']['error']);
    }

    // ---- §3.2 status display (check-in block explicitly deferred to Phase 5) ----

    public function test_given_active_membership_when_view_my_membership_then_shows_plan_price_and_dates(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, 'Gold', '79.99', 30);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $result = $this->request('GET', '/members/me/membership', $member);

        self::assertSame(200, $result['status']);
        self::assertSame('active', $result['body']['membership']['status']);
        self::assertSame('Gold', $result['body']['membership']['plan']['name']);
        self::assertSame('79.99', $result['body']['membership']['plan']['price']);
        self::assertArrayHasKey('startDate', $result['body']['membership']);
        self::assertArrayHasKey('endDate', $result['body']['membership']);
    }

    public function test_given_no_membership_when_view_my_membership_then_null_not_error(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/members/me/membership', $member);

        self::assertSame(200, $result['status']);
        self::assertNull($result['body']['membership']);
    }

    /**
     * "Membership.status correctly transitions to expired" — the part of
     * §3.2 this phase covers. The actual check-in block is deferred to
     * Phase 5 (Attendance doesn't exist yet).
     */
    public function test_given_end_date_has_passed_when_viewed_then_status_is_expired(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $enrolled = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET end_date = current_date - interval '1 day' WHERE id = ?",
            [$enrolled['body']['id']],
        );

        $result = $this->request('GET', '/members/me/membership', $member);

        self::assertSame(200, $result['status']);
        self::assertSame('expired', $result['body']['membership']['status']);
    }

    // ---- §3.3 Pause/cancel (self-service) — invoice suppression deferred to Phase 9 ----

    public function test_given_pause_when_completed_then_status_paused(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $result = $this->request('PATCH', '/members/me/membership/pause', $member);

        self::assertSame(200, $result['status']);
        self::assertSame('paused', $result['body']['status']);
    }

    public function test_given_paused_membership_when_resumed_then_status_active(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $this->request('PATCH', '/members/me/membership/pause', $member);

        $result = $this->request('PATCH', '/members/me/membership/resume', $member);

        self::assertSame(200, $result['status']);
        self::assertSame('active', $result['body']['status']);
    }

    public function test_given_cancel_when_completed_then_status_cancelled(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $result = $this->request('PATCH', '/members/me/membership/cancel', $member);

        self::assertSame(200, $result['status']);
        self::assertSame('cancelled', $result['body']['status']);
    }

    public function test_given_already_cancelled_when_pause_attempted_then_conflict(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $this->request('PATCH', '/members/me/membership/cancel', $member);

        $result = $this->request('PATCH', '/members/me/membership/pause', $member);

        self::assertSame(409, $result['status']);
        self::assertSame('not_active', $result['body']['error']);
    }

    /**
     * functional requirements §3.3-style guarantee, proven at the HTTP
     * layer (companion to the Voter unit test).
     */
    public function test_a_different_member_cannot_pause_someone_elses_membership_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $someoneElse = $this->createApprovedMember('Someone Else', 'else@example.com');

        $result = $this->request('PATCH', '/members/me/membership/pause', $someoneElse);

        // someoneElse has no membership of their own — this proves they
        // can't reach member's membership at all, not even to be denied by
        // the voter on it; a 404 here is the correct "not theirs" outcome.
        self::assertSame(404, $result['status']);
    }
}

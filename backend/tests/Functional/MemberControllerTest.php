<?php

namespace App\Tests\Functional;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AuditLogRepository;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Covers architecture doc §7's GET /members (Owner) — the roster page
 * this backs has no dedicated FR doc section (added directly against a
 * bug report, not a phase spec), so this is tested to the same standard
 * regardless: role gate, accurate membership data, the onboarded-vs-
 * pending-invitee distinction that decides who appears here at all, and
 * (on direct request) the roster's broadened scope to include coaches
 * alongside members, tagged with a `role` field.
 */
final class MemberControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE branch_assignment, branch, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(string $name, string $email, UserRole $role, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = new User($name, $email, null, $role, $status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createApprovedMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER);
        $this->em->persist(new MemberProfile($user));
        $this->em->flush();

        return $user;
    }

    private function createApprovedCoach(string $name, string $email, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = $this->createUser($name, $email, UserRole::COACH, $status);
        $this->em->persist(new CoachProfile($user));
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

    public function test_owner_sees_the_full_onboarded_roster(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createApprovedMember('Alice Approved', 'alice@example.com');
        $this->createApprovedMember('Bob Approved', 'bob@example.com');

        $result = $this->request('GET', '/members', $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['members']);
        // findAllWithUser() orders by name — Alice before Bob.
        self::assertSame('Alice Approved', $result['body']['members'][0]['name']);
        self::assertSame('member', $result['body']['members'][0]['role']);
        self::assertSame('active', $result['body']['members'][0]['status']);
        self::assertNull($result['body']['members'][0]['membership']);
    }

    public function test_roster_includes_coaches_tagged_with_a_role_field(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createApprovedMember('Alice Approved', 'alice@example.com');
        $this->createApprovedCoach('Carlos Coach', 'carlos@example.com');

        $result = $this->request('GET', '/members', $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['members']);
        $roles = array_column($result['body']['members'], 'role');
        sort($roles);
        self::assertSame(['coach', 'member'], $roles);

        $coach = current(array_filter($result['body']['members'], fn (array $m) => $m['role'] === 'coach'));
        self::assertSame('Carlos Coach', $coach['name']);
        self::assertNull($coach['membership']);
    }

    public function test_a_suspended_coach_still_appears_in_the_roster(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createApprovedCoach('Suspended Coach', 'suspended.coach@example.com', UserStatus::SUSPENDED);

        $result = $this->request('GET', '/members', $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $result['body']['members']);
        self::assertSame('suspended', $result['body']['members'][0]['status']);
    }

    /**
     * A member-role User exists (created via first OTP verify) but hasn't
     * approved their own invitation yet — no MemberProfile, so no roster
     * row. They're already visible via the Owner's Invitations panel;
     * this list is the actual onboarded roster, a different screen.
     */
    public function test_a_pending_approval_user_without_a_profile_is_not_in_the_roster(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createUser('Pending Person', 'pending@example.com', UserRole::MEMBER, UserStatus::PENDING_APPROVAL);

        $result = $this->request('GET', '/members', $owner);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['members']);
    }

    public function test_roster_includes_membership_plan_and_status_when_enrolled(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Gold', 'price' => '79.99', 'durationDays' => 30, 'features' => []]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);

        $result = $this->request('GET', '/members', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame('Gold', $result['body']['members'][0]['membership']['planName']);
        self::assertSame('active', $result['body']['members'][0]['membership']['status']);
    }

    /**
     * Bug fix: the Owner Members page's branch switcher never actually
     * filtered the roster — it only fed the front-desk check-in call. The
     * frontend fix filters client-side on this field, which didn't exist
     * before; this proves the backend actually supplies it.
     */
    public function test_roster_includes_the_members_enrolling_branch(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $plan = $this->request('POST', '/membership-plans', $owner, [
            'name' => 'Gold', 'price' => '79.99', 'durationDays' => 30, 'features' => [], 'branchId' => $downtown['id'],
        ]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);

        $result = $this->request('GET', '/members', $owner);

        self::assertSame([$downtown['id']], $result['body']['members'][0]['branchIds']);
    }

    public function test_an_unenrolled_member_has_no_branch_ids(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/members', $owner);

        self::assertSame([], $result['body']['members'][0]['branchIds']);
    }

    public function test_roster_includes_a_coachs_assigned_branches(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createApprovedCoach('Carlos Coach', 'carlos@example.com');
        $downtown = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $this->request('POST', "/branches/{$downtown['id']}/assign", $owner, ['userId' => (string) $coach->getId()]);

        $result = $this->request('GET', '/members', $owner);

        $roster = array_values(array_filter($result['body']['members'], fn (array $m) => $m['role'] === 'coach'));
        self::assertSame([$downtown['id']], $roster[0]['branchIds']);
    }

    public function test_an_expired_membership_shows_as_expired_not_stale_active(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Gold', 'price' => '79.99', 'durationDays' => 30, 'features' => []]);
        $enrolled = $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);
        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET end_date = current_date - interval '1 day' WHERE id = ?",
            [$enrolled['body']['id']],
        );

        $result = $this->request('GET', '/members', $owner);

        self::assertSame('expired', $result['body']['members'][0]['membership']['status']);
    }

    public function test_coach_cannot_list_members_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('GET', '/members', $coach);

        self::assertSame(403, $result['status']);
    }

    public function test_member_cannot_list_members_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/members', $member);

        self::assertSame(403, $result['status']);
    }

    // ---- PATCH /members/:id/status (Update/Delete: suspend, reactivate) ----

    public function test_owner_can_suspend_a_member(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);

        self::assertSame(200, $result['status']);
        self::assertSame('suspended', $result['body']['status']);
    }

    public function test_owner_can_reactivate_a_suspended_member(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'active']);

        self::assertSame(200, $result['status']);
        self::assertSame('active', $result['body']['status']);
    }

    public function test_a_member_cannot_suspend_themselves_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $member, ['status' => 'suspended']);

        self::assertSame(403, $result['status']);
    }

    public function test_a_coach_cannot_suspend_a_member_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $coach, ['status' => 'suspended']);

        self::assertSame(403, $result['status']);
    }

    public function test_status_update_rejects_pending_approval_as_a_target(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'pending_approval']);

        self::assertSame(400, $result['status']);
    }

    public function test_status_update_rejects_an_invalid_value(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'banned']);

        self::assertSame(400, $result['status']);
    }

    public function test_status_update_for_a_nonexistent_member_404(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('PATCH', '/members/' . Uuid::v7() . '/status', $owner, ['status' => 'suspended']);

        self::assertSame(404, $result['status']);
    }

    public function test_suspending_a_member_creates_an_audit_log_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('User', Uuid::fromString((string) $member->getId()));

        self::assertCount(1, $entries);
        self::assertSame('member.status_changed', $entries[0]->getAction());
        self::assertSame((string) $owner->getId(), (string) $entries[0]->getActor()->getId());
        self::assertSame('active', $entries[0]->getMetadata()['previousStatus']);
        self::assertSame('suspended', $entries[0]->getMetadata()['newStatus']);
    }

    /** Re-requesting the same status is a no-op — no duplicate audit noise. */
    public function test_setting_the_same_status_again_does_not_duplicate_the_audit_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);
        $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('User', Uuid::fromString((string) $member->getId()));

        self::assertCount(1, $entries);
    }

    /** Proves the Update action has real effect, not just a status label — functional requirements §4.1's "suspended... blocked" criterion. */
    public function test_a_suspended_member_is_then_blocked_from_checkin(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Gold', 'price' => '49.99', 'durationDays' => 30, 'features' => []]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);

        $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);
        $checkin = $this->request('POST', '/members/me/checkin', $member);

        self::assertSame(409, $checkin['status']);
        self::assertSame('checkin_blocked', $checkin['body']['error']);
        self::assertSame('account_suspended', $checkin['body']['reason']);
    }
}

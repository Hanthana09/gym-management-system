<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
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

    /**
     * MemberVoter::VIEW has always declared "Coach: own clients" (architecture
     * doc §9.1) — MemberProfile::hasCoach() only gained a real implementation
     * once PT sessions existed to derive it from. A Coach's own /members call
     * returns just their clients (no coach directory alongside, unlike
     * Owner/Staff's roster) — this is the Coach "Members" page's data source.
     */
    public function test_coach_sees_only_members_who_have_had_a_pt_session_with_them(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner-coach-roster@example.com', UserRole::OWNER);
        $gym = new Gym("Owner's Gym", '1 Main St', $owner);
        $this->em->persist($gym);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($branch);

        $coachUser = $this->createUser('Carlos Coach', 'coach-roster@example.com', UserRole::COACH);
        $coachProfile = new CoachProfile($coachUser);
        $this->em->persist($coachProfile);

        // Deliberately created BEFORE the actual client, so the survivor
        // isn't at findAllWithUser()'s index 0 — array_filter() preserves
        // source keys, and a regression there would json_encode() as a
        // JSON object (which the frontend can't parse as MemberListItemDto[])
        // while still passing an index-0-only assertion undetected.
        $this->createApprovedMember('Sam Stranger', 'sam-stranger@example.com');

        $clientUser = $this->createApprovedMember('Mia Client', 'mia-client@example.com');
        $clientProfile = $this->em->getRepository(MemberProfile::class)->findOneBy(['user' => $clientUser]);
        $this->em->persist(new PtSession($coachProfile, $clientProfile, $branch, new \DateTimeImmutable('+1 day'), 60));
        $this->em->flush();

        $result = $this->request('GET', '/members', $coachUser);

        self::assertSame(200, $result['status']);
        self::assertTrue(array_is_list($result['body']['members']), 'members must decode as a JSON array, not an object with gaps');
        self::assertCount(1, $result['body']['members']);
        self::assertSame('Mia Client', $result['body']['members'][0]['name']);
        // No coach-directory rows — unlike the Owner/Staff roster.
        self::assertSame('member', $result['body']['members'][0]['role']);
    }

    public function test_coach_with_no_pt_sessions_yet_sees_an_empty_roster_not_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $this->createApprovedMember('Mia Member', 'mia-unrelated@example.com');

        $result = $this->request('GET', '/members', $coach);

        self::assertSame(200, $result['status']);
        self::assertSame([], $result['body']['members']);
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

    // ---- gym-management-member-profile-extension.md: POST /members (manual walk-in) ----

    public function test_owner_can_create_a_member_manually(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/members', $owner, [
            'name' => 'Walter Walkin',
            'email' => 'walter@example.com',
            'dob' => '1990-05-15',
            'gender' => 'male',
            'addressLine' => '12 Elm St',
            'addressCity' => 'Springfield',
            'addressPostalCode' => '00100',
        ]);

        self::assertSame(201, $result['status']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]+-\d{4}$/', $result['body']['memberId']);
        self::assertSame('active', $result['body']['status']);
        self::assertSame(1990, (new \DateTimeImmutable($result['body']['dob']))->format('Y') + 0);
        self::assertSame('male', $result['body']['gender']);
        self::assertIsInt($result['body']['age']);
    }

    /** Follow-up feature (editable/manual Member ID mode): widened from Owner-only — front-desk registration is typically a Staff task. */
    public function test_staff_can_create_a_member_manually(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St']); // lazily provisions the gym
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);

        $result = $this->request('POST', '/members', $staff, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);

        self::assertSame(201, $result['status']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]+-\d{4}$/', $result['body']['memberId']);
    }

    public function test_coach_cannot_create_a_member_manually_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St']);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('POST', '/members', $coach, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);

        self::assertSame(403, $result['status']);
    }

    public function test_creating_a_member_with_a_memberId_in_the_payload_is_rejected(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/members', $owner, [
            'name' => 'Walter Walkin', 'email' => 'walter@example.com', 'memberId' => 'HACK-0001',
        ]);

        self::assertSame(422, $result['status']);
    }

    /** Race-safety proof at the app level: sequential calls for the same Owner get distinct, sequential memberIds — the atomic UPSERT guarantees this holds under real concurrency too. */
    public function test_two_members_created_in_a_row_get_distinct_sequential_member_ids(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $first = $this->request('POST', '/members', $owner, ['name' => 'First Walkin', 'email' => 'first@example.com']);
        $second = $this->request('POST', '/members', $owner, ['name' => 'Second Walkin', 'email' => 'second@example.com']);

        self::assertNotSame($first['body']['memberId'], $second['body']['memberId']);
        $prefix = substr((string) $first['body']['memberId'], 0, strrpos((string) $first['body']['memberId'], '-'));
        self::assertSame($prefix . '-0002', $second['body']['memberId']);
    }

    // ---- GET/PATCH /members/:id (profile) ----

    public function test_owner_can_read_a_members_full_profile(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}", $owner);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('dob', $result['body']);
        self::assertArrayHasKey('gender', $result['body']);
        self::assertArrayHasKey('addressLine', $result['body']);
        self::assertNull($result['body']['age']); // no dob set
    }

    public function test_member_can_read_their_own_full_profile(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}", $member);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('dob', $result['body']);
    }

    public function test_a_different_member_cannot_read_someone_elses_profile_403(): void
    {
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $someoneElse = $this->createApprovedMember('Owen Other', 'owen@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}", $someoneElse);

        self::assertSame(403, $result['status']);
    }

    public function test_coach_cannot_read_a_members_full_profile_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}", $coach);

        self::assertSame(403, $result['status']);
    }

    /**
     * gym-management-member-profile-extension.md §7: the Coach-facing
     * member picker (workout-scheduling's own endpoint, untouched by this
     * phase) must never gain the new PII fields — asserted directly on
     * its response shape, not just inferred from it being unreachable.
     */
    public function test_coach_facing_member_list_never_exposes_the_new_pii_fields(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/workout-assignments/members', $coach);

        self::assertSame(200, $result['status']);
        self::assertNotEmpty($result['body']['members']);
        foreach ($result['body']['members'] as $entry) {
            self::assertArrayNotHasKey('dob', $entry);
            self::assertArrayNotHasKey('gender', $entry);
            self::assertArrayNotHasKey('addressLine', $entry);
        }
    }

    public function test_owner_can_update_a_members_profile_fields(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}", $owner, [
            'dob' => '1995-01-20',
            'gender' => 'female',
            'addressLine' => '5 Oak Ave',
            'addressCity' => 'Metropolis',
            'addressPostalCode' => '20001',
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame('1995-01-20', $result['body']['dob']);
        self::assertSame('female', $result['body']['gender']);
        self::assertSame('5 Oak Ave', $result['body']['addressLine']);
        self::assertIsInt($result['body']['age']);
    }

    /** Follow-up feature (editable/manual Member ID mode): MemberVoter::EDIT_PROFILE grants Staff gym-wide, unscoped — unlike MANAGE (suspend/reactivate), which stays Owner-only. */
    public function test_staff_can_update_a_members_profile_fields(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St']); // lazily provisions the gym
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}", $staff, ['dob' => '1995-01-20']);

        self::assertSame(200, $result['status']);
        self::assertSame('1995-01-20', $result['body']['dob']);
    }

    public function test_staff_still_cannot_suspend_a_member_403(): void
    {
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}/status", $staff, ['status' => 'suspended']);

        self::assertSame(403, $result['status']);
    }

    public function test_coach_cannot_update_a_members_profile_fields_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}", $coach, ['dob' => '1995-01-20']);

        self::assertSame(403, $result['status']);
    }

    /** functional requirements-style negative case: memberId is immutable, rejected even attached to an otherwise-valid update. */
    public function test_updating_a_member_with_a_memberId_in_the_payload_is_rejected_and_value_unchanged(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $created = $this->request('POST', '/members', $owner, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);
        $originalMemberId = $created['body']['memberId'];

        $result = $this->request('PATCH', "/members/{$created['body']['id']}", $owner, [
            'memberId' => 'HACKED-0001',
            'addressCity' => 'Newtown',
        ]);

        self::assertSame(422, $result['status']);

        $unchanged = $this->request('GET', "/members/{$created['body']['id']}", $owner);
        self::assertSame($originalMemberId, $unchanged['body']['memberId']);
        self::assertNull($unchanged['body']['addressCity']); // the rest of the payload was rejected too, not partially applied
    }

    public function test_dob_is_rejected_when_not_a_valid_date(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}", $owner, ['dob' => 'not-a-date']);

        self::assertSame(400, $result['status']);
    }

    // ---- cross-gym isolation (gym-management-member-profile-extension.md §6.1/§7) ----

    public function test_owner_of_a_different_gym_cannot_read_or_write_a_members_profile_403(): void
    {
        $ownerA = $this->createUser('Olivia OwnerA', 'ownerA@example.com', UserRole::OWNER);
        $ownerB = $this->createUser('Bella OwnerB', 'ownerB@example.com', UserRole::OWNER);
        $created = $this->request('POST', '/members', $ownerA, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);

        $read = $this->request('GET', "/members/{$created['body']['id']}", $ownerB);
        $write = $this->request('PATCH', "/members/{$created['body']['id']}", $ownerB, ['addressCity' => 'Newtown']);

        self::assertSame(403, $read['status']);
        self::assertSame(403, $write['status']);
    }

    // ---- PT schedule / attendance / payments tabs ----

    public function test_owner_can_read_a_members_pt_schedule(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}/pt-schedule", $owner);

        self::assertSame(200, $result['status']);
        self::assertSame([], $result['body']['ptSessions']);
        self::assertSame([], $result['body']['workoutAssignments']);
    }

    public function test_coach_cannot_read_a_members_pt_schedule_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}/pt-schedule", $coach);

        self::assertSame(403, $result['status']);
    }

    public function test_coach_cannot_read_a_members_payment_history_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}/payments", $coach);

        self::assertSame(403, $result['status']);
    }

    public function test_payment_history_is_an_explicit_not_available_stub_not_an_empty_list(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}/payments", $owner);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['body']['available']);
        self::assertSame('not_yet_available', $result['body']['reason']);
        self::assertSame([], $result['body']['payments']);
    }

    /** Deactivation is a status transition, not a row deletion — history stays queryable via the existing endpoints. */
    public function test_a_deactivated_members_attendance_history_stays_queryable(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->request('POST', '/membership-plans', $owner, ['name' => 'Gold', 'price' => '49.99', 'durationDays' => 30, 'features' => []]);
        $this->request('POST', '/memberships', $owner, ['memberUserId' => (string) $member->getId(), 'planId' => $plan['body']['id']]);
        $this->request('POST', '/members/me/checkin', $member);

        $this->request('PATCH', "/members/{$member->getId()}/status", $owner, ['status' => 'suspended']);
        $result = $this->request('GET', "/members/{$member->getId()}/attendance", $owner);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['body']['total']);
        self::assertCount(1, $result['body']['logs']);
    }

    // ---- getAge() ----

    public function test_age_is_null_when_dob_is_not_set(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', "/members/{$member->getId()}", $owner);

        self::assertNull($result['body']['age']);
    }

    public function test_age_is_computed_correctly_once_dob_is_set(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $twentyYearsAgo = (new \DateTimeImmutable('-20 years -1 day'))->format('Y-m-d');

        $this->request('PATCH', "/members/{$member->getId()}", $owner, ['dob' => $twentyYearsAgo]);
        $result = $this->request('GET', "/members/{$member->getId()}", $owner);

        self::assertSame(20, $result['body']['age']);
    }

    // ---- follow-up feature: editable/manual Member ID mode ----

    private function switchToManualMode(User $owner): void
    {
        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['mode' => 'manual']);
        self::assertSame(200, $result['status']);
    }

    public function test_manual_mode_requires_member_id_on_create(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->switchToManualMode($owner);

        $result = $this->request('POST', '/members', $owner, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);

        self::assertSame(400, $result['status']);
    }

    public function test_manual_mode_accepts_a_hand_entered_member_id_on_create(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->switchToManualMode($owner);

        $result = $this->request('POST', '/members', $owner, [
            'name' => 'Walter Walkin', 'email' => 'walter@example.com', 'memberId' => 'LEGACY-042',
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame('LEGACY-042', $result['body']['memberId']);
    }

    public function test_manual_mode_rejects_a_duplicate_member_id(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->switchToManualMode($owner);
        $this->request('POST', '/members', $owner, ['name' => 'First Walkin', 'email' => 'first@example.com', 'memberId' => 'LEGACY-042']);

        $result = $this->request('POST', '/members', $owner, ['name' => 'Second Walkin', 'email' => 'second@example.com', 'memberId' => 'LEGACY-042']);

        self::assertSame(409, $result['status']);
    }

    /** Unlike auto mode's immutable-once-assigned rule, manual mode allows correcting a front-desk typo anytime. */
    public function test_manual_mode_allows_editing_the_member_id_later(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->switchToManualMode($owner);
        $created = $this->request('POST', '/members', $owner, [
            'name' => 'Walter Walkin', 'email' => 'walter@example.com', 'memberId' => 'LEGACY-042',
        ]);

        $result = $this->request('PATCH', "/members/{$created['body']['id']}", $owner, ['memberId' => 'LEGACY-043']);

        self::assertSame(200, $result['status']);
        self::assertSame('LEGACY-043', $result['body']['memberId']);
    }

    public function test_manual_mode_rejects_an_empty_member_id_on_update(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->switchToManualMode($owner);
        $created = $this->request('POST', '/members', $owner, [
            'name' => 'Walter Walkin', 'email' => 'walter@example.com', 'memberId' => 'LEGACY-042',
        ]);

        $result = $this->request('PATCH', "/members/{$created['body']['id']}", $owner, ['memberId' => '']);

        self::assertSame(400, $result['status']);
    }

    /** Regression: auto mode (the default) is untouched by this feature — still 422, still immutable via the payload. */
    public function test_auto_mode_still_rejects_member_id_in_payload(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');

        $result = $this->request('PATCH', "/members/{$member->getId()}", $owner, ['memberId' => 'HACKED-0001']);

        self::assertSame(422, $result['status']);
    }
}

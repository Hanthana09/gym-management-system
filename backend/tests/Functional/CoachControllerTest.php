<?php

namespace App\Tests\Functional;

use App\Entity\CoachProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AuditLogRepository;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * gym-management-coach-management.md: coach CRUD for an Owner —
 * POST /coaches (direct create), GET/PATCH /coaches/:id, and
 * PATCH /coaches/:id/status. Role gate + CoachManagementVoter, audit
 * entries, idempotent status change, contact/uniqueness validation.
 */
final class CoachControllerTest extends WebTestCase
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

    private function createUser(string $name, ?string $email, UserRole $role, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = new User($name, $email, null, $role, $status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createCoach(string $name, string $email, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = $this->createUser($name, $email, UserRole::COACH, $status);
        $this->em->persist(new CoachProfile($user));
        $this->em->flush();

        return $user;
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            '/api' . $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(TokenIssuer::class)->createAccessToken($actingAs),
            ],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    // ---- create ------------------------------------------------------

    public function test_owner_creates_an_immediately_active_coach(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/coaches', $owner, [
            'name' => 'Cara Coach',
            'email' => 'cara@example.com',
            'specialty' => 'Strength',
            'bio' => 'Ten years on the platform.',
            'hourlyRate' => '55',
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame('coach', $result['body']['role']);
        self::assertSame('active', $result['body']['status']);
        self::assertSame('Strength', $result['body']['specialty']);
        self::assertSame('55.00', $result['body']['hourlyRate']);
    }

    public function test_creating_a_coach_writes_an_audit_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $created = $this->request('POST', '/coaches', $owner, ['name' => 'Cara Coach', 'email' => 'cara@example.com']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('User', Uuid::fromString($created['body']['id']));

        self::assertCount(1, $entries);
        self::assertSame('coach.created', $entries[0]->getAction());
    }

    public function test_create_requires_a_name_and_a_contact_method(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/coaches', $owner, ['name' => 'Cara Coach']);

        self::assertSame(400, $result['status']);
    }

    public function test_create_rejects_a_duplicate_email_409(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createCoach('Existing Coach', 'taken@example.com');

        $result = $this->request('POST', '/coaches', $owner, ['name' => 'Cara Coach', 'email' => 'taken@example.com']);

        self::assertSame(409, $result['status']);
    }

    public function test_create_rejects_a_negative_hourly_rate_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/coaches', $owner, ['name' => 'Cara Coach', 'email' => 'cara@example.com', 'hourlyRate' => '-5']);

        self::assertSame(400, $result['status']);
    }

    public function test_coach_cannot_create_a_coach_403(): void
    {
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('POST', '/coaches', $coach, ['name' => 'New Coach', 'email' => 'new@example.com']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_create_a_coach_403(): void
    {
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);

        $result = $this->request('POST', '/coaches', $staff, ['name' => 'New Coach', 'email' => 'new@example.com']);

        self::assertSame(403, $result['status']);
    }

    // ---- read -------------------------------------------------------

    public function test_owner_reads_a_coach_detail(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('GET', "/coaches/{$coach->getId()}", $owner);

        self::assertSame(200, $result['status']);
        self::assertSame('Cara Coach', $result['body']['name']);
    }

    public function test_a_coach_can_read_their_own_detail(): void
    {
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('GET', "/coaches/{$coach->getId()}", $coach);

        self::assertSame(200, $result['status']);
    }

    public function test_a_member_cannot_read_a_coach_detail_403(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('GET', "/coaches/{$coach->getId()}", $member);

        self::assertSame(403, $result['status']);
    }

    public function test_get_a_nonexistent_coach_404(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('GET', '/coaches/' . Uuid::v7(), $owner);

        self::assertSame(404, $result['status']);
    }

    // ---- update ---------------------------------------------------

    public function test_owner_updates_identity_and_profile_fields(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('PATCH', "/coaches/{$coach->getId()}", $owner, [
            'name' => 'Cara Coach-Smith',
            'specialty' => 'Mobility',
            'hourlyRate' => '70.5',
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame('Cara Coach-Smith', $result['body']['name']);
        self::assertSame('Mobility', $result['body']['specialty']);
        self::assertSame('70.50', $result['body']['hourlyRate']);
    }

    public function test_update_writes_an_audit_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $this->request('PATCH', "/coaches/{$coach->getId()}", $owner, ['specialty' => 'Mobility']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('User', Uuid::fromString((string) $coach->getId()));

        self::assertCount(1, $entries);
        self::assertSame('coach.profile_updated', $entries[0]->getAction());
        self::assertSame(['specialty'], $entries[0]->getMetadata()['fields']);
    }

    public function test_update_cannot_remove_the_last_contact_method_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('PATCH', "/coaches/{$coach->getId()}", $owner, ['email' => null]);

        self::assertSame(400, $result['status']);
    }

    public function test_update_rejects_an_email_already_used_by_another_user_409(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $this->createCoach('Other Coach', 'other@example.com');
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('PATCH', "/coaches/{$coach->getId()}", $owner, ['email' => 'other@example.com']);

        self::assertSame(409, $result['status']);
    }

    public function test_coach_cannot_update_another_coach_403(): void
    {
        $actor = $this->createCoach('Cara Coach', 'cara@example.com');
        $target = $this->createCoach('Other Coach', 'other@example.com');

        $result = $this->request('PATCH', "/coaches/{$target->getId()}", $actor, ['specialty' => 'Mobility']);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_update_a_coach_403(): void
    {
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('PATCH', "/coaches/{$coach->getId()}", $staff, ['specialty' => 'Mobility']);

        self::assertSame(403, $result['status']);
    }

    // ---- status --------------------------------------------------

    public function test_owner_suspends_and_reactivates_a_coach(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $suspended = $this->request('PATCH', "/coaches/{$coach->getId()}/status", $owner, ['status' => 'suspended']);
        self::assertSame(200, $suspended['status']);
        self::assertSame('suspended', $suspended['body']['status']);

        $reactivated = $this->request('PATCH', "/coaches/{$coach->getId()}/status", $owner, ['status' => 'active']);
        self::assertSame('active', $reactivated['body']['status']);
    }

    public function test_suspending_a_coach_writes_an_audit_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $this->request('PATCH', "/coaches/{$coach->getId()}/status", $owner, ['status' => 'suspended']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('User', Uuid::fromString((string) $coach->getId()));

        self::assertCount(1, $entries);
        self::assertSame('coach.status_changed', $entries[0]->getAction());
        self::assertSame('suspended', $entries[0]->getMetadata()['newStatus']);
    }

    public function test_setting_the_same_status_again_does_not_duplicate_the_audit_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $this->request('PATCH', "/coaches/{$coach->getId()}/status", $owner, ['status' => 'suspended']);
        $this->request('PATCH', "/coaches/{$coach->getId()}/status", $owner, ['status' => 'suspended']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('User', Uuid::fromString((string) $coach->getId()));

        self::assertCount(1, $entries);
    }

    public function test_status_rejects_an_invalid_value_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('PATCH', "/coaches/{$coach->getId()}/status", $owner, ['status' => 'pending_approval']);

        self::assertSame(400, $result['status']);
    }

    public function test_coach_cannot_change_their_own_status_403(): void
    {
        $coach = $this->createCoach('Cara Coach', 'cara@example.com');

        $result = $this->request('PATCH', "/coaches/{$coach->getId()}/status", $coach, ['status' => 'suspended']);

        self::assertSame(403, $result['status']);
    }
}

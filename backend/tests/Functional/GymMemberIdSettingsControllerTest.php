<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Follow-up feature: "editable/manual Member ID mode" — lets an Owner
 * switch between Setly's auto-generated memberId sequence and entering
 * a gym's own numbering scheme by hand. Owner-only, reuses GymVoter::
 * MANAGE the same way branding/WhatsApp settings do.
 */
final class GymMemberIdSettingsControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE member_sequence, member_profile, invitation, gym, "user" CASCADE',
        );
    }

    private function createUser(string $name, UserRole $role): User
    {
        $user = new User($name, strtolower(str_replace(' ', '', $name)) . '@example.com', null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $token = static::getContainer()->get(TokenIssuer::class)->createAccessToken($actingAs);
        $this->client->request(
            $method,
            '/api' . $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    public function test_owner_sees_auto_default_before_any_gym_exists(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('GET', '/gym/member-id-settings', $owner);

        self::assertSame(200, $result['status']);
        self::assertSame('auto', $result['body']['mode']);
        self::assertNull($result['body']['gymCode']);
    }

    public function test_owner_can_switch_to_manual_mode(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['mode' => 'manual']);

        self::assertSame(200, $result['status']);
        self::assertSame('manual', $result['body']['mode']);
    }

    public function test_owner_can_set_a_gym_code(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['gymCode' => 'acme']);

        self::assertSame(200, $result['status']);
        self::assertSame('ACME', $result['body']['gymCode']);
    }

    public function test_gym_code_must_be_unique(): void
    {
        $ownerA = $this->createUser('Olivia OwnerA', UserRole::OWNER);
        $ownerB = $this->createUser('Bella OwnerB', UserRole::OWNER);
        $this->request('PATCH', '/gym/member-id-settings', $ownerA, ['gymCode' => 'ACME']);

        $result = $this->request('PATCH', '/gym/member-id-settings', $ownerB, ['gymCode' => 'ACME']);

        self::assertSame(400, $result['status']);
    }

    public function test_gym_code_rejects_invalid_format(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['gymCode' => 'a']);

        self::assertSame(400, $result['status']);
    }

    public function test_mode_switch_is_blocked_once_the_gym_has_members(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $created = $this->request('POST', '/members', $owner, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);
        self::assertSame(201, $created['status']);

        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['mode' => 'manual']);

        self::assertSame(400, $result['status']);
    }

    /** Re-submitting the gym's current mode is a no-op, not a "change" — must not trip the existsForGym guard. */
    public function test_resubmitting_the_same_mode_is_not_blocked_by_existing_members(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $this->request('POST', '/members', $owner, ['name' => 'Walter Walkin', 'email' => 'walter@example.com']);

        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['mode' => 'auto']);

        self::assertSame(200, $result['status']);
    }

    public function test_invalid_mode_value_is_rejected(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('PATCH', '/gym/member-id-settings', $owner, ['mode' => 'sometimes']);

        self::assertSame(400, $result['status']);
    }

    /** Staff needs to read the mode (to render the Add Member/profile-edit form correctly) even though only Owner can change it. */
    public function test_staff_can_view_member_id_settings(): void
    {
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);

        $result = $this->request('GET', '/gym/member-id-settings', $staff);

        self::assertSame(200, $result['status']);
        self::assertSame('auto', $result['body']['mode']);
    }

    /**
     * Regression: PATCH used to call ensureGymForOwner($user) before the
     * role/Voter check, so a non-Owner would create a bogus Gym "owned"
     * by themselves as a side effect of a request that's denied anyway.
     * The 403 itself was never in question (GymVoter::MANAGE already
     * requires isOwner()) — this asserts the side effect is gone too.
     */
    public function test_staff_updating_member_id_settings_does_not_create_a_bogus_gym(): void
    {
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);

        $this->request('PATCH', '/gym/member-id-settings', $staff, ['mode' => 'manual']);

        $gymCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM gym');
        self::assertSame(0, $gymCount);
    }

    public function test_coach_cannot_view_member_id_settings_403(): void
    {
        $coach = $this->createUser('Carlos Coach', UserRole::COACH);

        $result = $this->request('GET', '/gym/member-id-settings', $coach);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_update_member_id_settings_403(): void
    {
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);

        $result = $this->request('PATCH', '/gym/member-id-settings', $staff, ['mode' => 'manual']);

        self::assertSame(403, $result['status']);
    }

    public function test_member_cannot_view_or_update_member_id_settings_403(): void
    {
        $member = $this->createUser('Mia Member', UserRole::MEMBER);

        self::assertSame(403, $this->request('GET', '/gym/member-id-settings', $member)['status']);
        self::assertSame(403, $this->request('PATCH', '/gym/member-id-settings', $member, ['mode' => 'manual'])['status']);
    }
}

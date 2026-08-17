<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * WhatsApp admin config follow-up to roadmap Phase 15.3: a gym-wide
 * on/off switch and credential setup, Owner-only, reusing GymVoter::MANAGE
 * the same way branding does.
 */
final class GymWhatsAppSettingsControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE gym, "user" CASCADE');
    }

    private function createUser(string $name, UserRole $role): User
    {
        $user = new User($name, $name . '@example.com', null, $role, UserStatus::ACTIVE);
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

    public function test_owner_sees_unconfigured_defaults_before_any_gym_exists(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('GET', '/gym/whatsapp-settings', $owner);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['body']['enabled']);
        self::assertFalse($result['body']['accessTokenSet']);
        self::assertNull($result['body']['phoneNumberId']);
    }

    public function test_owner_can_configure_credentials_and_the_access_token_is_never_echoed_back(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('PATCH', '/gym/whatsapp-settings', $owner, [
            'accessToken' => 'super-secret-token',
            'phoneNumberId' => '123456789',
        ]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['accessTokenSet']);
        self::assertSame('123456789', $result['body']['phoneNumberId']);
        self::assertArrayNotHasKey('accessToken', $result['body']);
        self::assertStringNotContainsString('super-secret-token', $this->client->getResponse()->getContent());
    }

    public function test_owner_can_enable_once_credentials_are_configured(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $this->request('PATCH', '/gym/whatsapp-settings', $owner, [
            'accessToken' => 'super-secret-token',
            'phoneNumberId' => '123456789',
        ]);

        $result = $this->request('PATCH', '/gym/whatsapp-settings', $owner, ['enabled' => true]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['enabled']);
    }

    /** functional requirement of the admin section: turning it on without credentials is a specific, immediate error, not a silent no-op later. */
    public function test_enabling_without_credentials_configured_is_rejected(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);

        $result = $this->request('PATCH', '/gym/whatsapp-settings', $owner, ['enabled' => true]);

        self::assertSame(400, $result['status']);
    }

    public function test_owner_can_disable_and_clear_credentials(): void
    {
        $owner = $this->createUser('Olivia Owner', UserRole::OWNER);
        $this->request('PATCH', '/gym/whatsapp-settings', $owner, [
            'accessToken' => 'super-secret-token',
            'phoneNumberId' => '123456789',
            'enabled' => true,
        ]);

        $result = $this->request('PATCH', '/gym/whatsapp-settings', $owner, [
            'enabled' => false,
            'accessToken' => '',
            'phoneNumberId' => '',
        ]);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['body']['enabled']);
        self::assertFalse($result['body']['accessTokenSet']);
        self::assertNull($result['body']['phoneNumberId']);
    }

    public function test_coach_cannot_view_whatsapp_settings_403(): void
    {
        $coach = $this->createUser('Cara Coach', UserRole::COACH);

        $result = $this->request('GET', '/gym/whatsapp-settings', $coach);

        self::assertSame(403, $result['status']);
    }

    public function test_staff_cannot_update_whatsapp_settings_403(): void
    {
        $staff = $this->createUser('Sam Staff', UserRole::STAFF);

        $result = $this->request('PATCH', '/gym/whatsapp-settings', $staff, ['enabled' => true]);

        self::assertSame(403, $result['status']);
    }

    public function test_member_cannot_view_or_update_whatsapp_settings_403(): void
    {
        $member = $this->createUser('Mia Member', UserRole::MEMBER);

        self::assertSame(403, $this->request('GET', '/gym/whatsapp-settings', $member)['status']);
        self::assertSame(403, $this->request('PATCH', '/gym/whatsapp-settings', $member, ['enabled' => true])['status']);
    }
}

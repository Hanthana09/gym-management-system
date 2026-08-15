<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * roadmap Phase 15.2 / functional requirements §12.1. `PATCH
 * /gym/branding` is multipart (a logo file plus a brandColor field), so
 * this doesn't reuse StaffAccessTest's JSON-only `request()` helper.
 */
final class GymBrandingControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE audit_log, daily_metric_snapshot, invoice, attendance_log, pt_session, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    private function createUser(string $name, string $email, UserRole $role): User
    {
        $user = new User($name, $email, null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    private function authHeaders(User $user): array
    {
        return ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($user)];
    }

    private function tempImage(string $mimeType = 'image/png'): UploadedFile
    {
        // A real 1x1 PNG — Symfony's UploadedFile::getMimeType() guesses
        // from actual file content, not the filename or a client-supplied
        // header, so a fake extension alone wouldn't pass validation.
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($path, $pngBytes);

        return new UploadedFile($path, 'logo.png', $mimeType, null, true);
    }

    public function test_owner_can_set_the_gym_name(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $this->client->request('PATCH', '/gym/branding', ['name' => 'Iron Temple Gym'], [], $this->authHeaders($owner));
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Iron Temple Gym', $result['name']);
    }

    public function test_an_empty_gym_name_is_rejected_400(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $this->client->request('PATCH', '/gym/branding', ['name' => '   '], [], $this->authHeaders($owner));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function test_coach_cannot_set_the_gym_name_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);

        $this->client->request('PATCH', '/gym/branding', ['name' => 'Hijacked Gym'], [], $this->authHeaders($coach));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function test_member_sees_the_gym_name_once_set(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $this->client->request('PATCH', '/gym/branding', ['name' => 'Iron Temple Gym'], [], $this->authHeaders($owner));

        $this->client->request('GET', '/gym/branding', server: $this->authHeaders($member));
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('Iron Temple Gym', $result['name']);
    }

    public function test_owner_can_set_brand_color(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $this->client->request('PATCH', '/gym/branding', ['brandColor' => '#1A2B3C'], [], $this->authHeaders($owner));
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('#1A2B3C', $result['brandColor']);
    }

    public function test_owner_can_upload_a_logo(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $this->client->request(
            'PATCH',
            '/gym/branding',
            [],
            ['logo' => $this->tempImage()],
            $this->authHeaders($owner),
        );
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('/uploads/gym-logos/', $result['logoUrl']);
    }

    public function test_invalid_brand_color_is_rejected(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $this->client->request('PATCH', '/gym/branding', ['brandColor' => 'not-a-color'], [], $this->authHeaders($owner));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /** functional requirements §12.1: a crafted request can't smuggle branding into fields it doesn't own (hivis/role-tags aren't columns on Gym at all, so this is really "unknown fields are ignored, not a 500"). */
    public function test_unrecognized_fields_are_ignored_not_persisted(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $this->client->request('PATCH', '/gym/branding', ['hivisColor' => '#FF0000', 'brandColor' => '#00FF00'], [], $this->authHeaders($owner));
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('#00FF00', $result['brandColor']);
        self::assertArrayNotHasKey('hivisColor', $result);
    }

    public function test_coach_cannot_set_branding_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);

        $this->client->request('PATCH', '/gym/branding', ['brandColor' => '#1A2B3C'], [], $this->authHeaders($coach));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function test_staff_cannot_set_branding_403(): void
    {
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);

        $this->client->request('PATCH', '/gym/branding', ['brandColor' => '#1A2B3C'], [], $this->authHeaders($staff));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function test_member_cannot_set_branding_403(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        $this->client->request('PATCH', '/gym/branding', ['brandColor' => '#1A2B3C'], [], $this->authHeaders($member));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** functional requirements §12.1: "when a Member views the app, then a sensible default is shown — never a broken image or empty color swatch." */
    public function test_any_authenticated_role_can_read_branding_even_with_no_gym_yet(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        $this->client->request('GET', '/gym/branding', server: $this->authHeaders($member));
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($result['logoUrl']);
        self::assertNull($result['brandColor']);
    }

    public function test_member_sees_owners_branding_once_set(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        $this->client->request('PATCH', '/gym/branding', ['brandColor' => '#1A2B3C'], [], $this->authHeaders($owner));

        $this->client->request('GET', '/gym/branding', server: $this->authHeaders($member));
        $result = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('#1A2B3C', $result['brandColor']);
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers roadmap Phase 9.2 (GTM Pillar B — Coach-led growth, Pillar F —
 * Owner referral). No formal FR doc section exists for this — per the
 * task, the roadmap's Phase 9.2 bullets and the go-to-market pillar
 * descriptions are the behavioral spec here, tested to the same standard
 * as an FR-backed feature.
 */
final class ReferralControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE referral_lead, referral_code, notification, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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

    // ---- Lead capture (Pillar B / Pillar F) -----------------------------

    public function test_coach_can_submit_a_lead_and_it_is_attributed_to_them(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('POST', '/referrals', $coach, [
            'prospectGymName' => 'Riverside Fitness',
            'contactName' => 'Pat Prospect',
            'contactEmail' => 'pat@riverside.example.com',
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame('Riverside Fitness', $result['body']['prospectGymName']);
        self::assertSame('new', $result['body']['status']);

        // Attribution: it shows up on THIS coach's own list.
        $mine = $this->request('GET', '/referrals/me', $coach);
        self::assertCount(1, $mine['body']['leads']);
        self::assertSame('Riverside Fitness', $mine['body']['leads'][0]['prospectGymName']);

        $referredBy = $this->em->getConnection()->fetchOne(
            'SELECT referred_by FROM referral_lead WHERE prospect_gym_name = ?',
            ['Riverside Fitness'],
        );
        self::assertSame((string) $coach->getId(), $referredBy);
    }

    public function test_owner_can_also_submit_a_lead(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $result = $this->request('POST', '/referrals', $owner, [
            'prospectGymName' => 'Uptown Gym',
            'contactPhone' => '+15550008888',
        ]);

        self::assertSame(201, $result['status']);
    }

    public function test_member_cannot_submit_a_lead_403(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        $result = $this->request('POST', '/referrals', $member, ['prospectGymName' => 'X Gym', 'contactEmail' => 'x@example.com']);

        self::assertSame(403, $result['status']);
    }

    public function test_missing_prospect_name_returns_400(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('POST', '/referrals', $coach, ['contactEmail' => 'x@example.com']);

        self::assertSame(400, $result['status']);
    }

    public function test_missing_contact_info_returns_400(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('POST', '/referrals', $coach, ['prospectGymName' => 'X Gym']);

        self::assertSame(400, $result['status']);
    }

    public function test_a_users_own_leads_never_include_someone_elses(): void
    {
        $coachA = $this->createUser('Coach A', 'coacha@example.com', UserRole::COACH);
        $coachB = $this->createUser('Coach B', 'coachb@example.com', UserRole::COACH);
        $this->request('POST', '/referrals', $coachB, ['prospectGymName' => 'B Gym', 'contactEmail' => 'b@example.com']);

        $result = $this->request('GET', '/referrals/me', $coachA);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['leads']);
    }

    // ---- Referral code (Pillar F) ----------------------------------------

    public function test_owner_gets_a_referral_code_lazily_and_it_is_stable_across_requests(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);

        $first = $this->request('GET', '/referral-code', $owner);
        $second = $this->request('GET', '/referral-code', $owner);

        self::assertSame(200, $first['status']);
        self::assertSame($first['body']['code'], $second['body']['code']);
        self::assertSame(0, $first['body']['usageCount']);
    }

    public function test_referral_codes_are_unique_per_owner(): void
    {
        $ownerA = $this->createUser('Owner A', 'ownera@example.com', UserRole::OWNER);
        $ownerB = $this->createUser('Owner B', 'ownerb@example.com', UserRole::OWNER);

        $codeA = $this->request('GET', '/referral-code', $ownerA)['body']['code'];
        $codeB = $this->request('GET', '/referral-code', $ownerB)['body']['code'];

        self::assertNotSame($codeA, $codeB);

        $distinctCount = $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT owner_id) FROM referral_code');
        self::assertSame('2', (string) $distinctCount);
    }

    public function test_coach_cannot_get_a_referral_code_403(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('GET', '/referral-code', $coach);

        self::assertSame(403, $result['status']);
    }

    public function test_redeeming_a_code_increments_usage_count_correctly(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $someone = $this->createUser('Someone', 'someone@example.com', UserRole::MEMBER);
        $code = $this->request('GET', '/referral-code', $owner)['body']['code'];

        $this->request('POST', '/referral-code/redeem', $someone, ['code' => $code]);
        $this->request('POST', '/referral-code/redeem', $someone, ['code' => $code]);
        $third = $this->request('POST', '/referral-code/redeem', $someone, ['code' => $code]);

        self::assertSame(200, $third['status']);
        self::assertSame(3, $third['body']['usageCount']);

        $fetched = $this->request('GET', '/referral-code', $owner);
        self::assertSame(3, $fetched['body']['usageCount']);
    }

    public function test_redeeming_an_unknown_code_returns_404(): void
    {
        $someone = $this->createUser('Someone', 'someone@example.com', UserRole::MEMBER);

        $result = $this->request('POST', '/referral-code/redeem', $someone, ['code' => 'DOES-NOT-EXIST']);

        self::assertSame(404, $result['status']);
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers functional requirements §7.1 (workout logging) and §7.2 (body
 * metrics/progress, including the empty-state and private-by-default
 * criteria). The private-by-default guarantee is primarily proven at the
 * Voter unit level (PersonalTrackingVoterTest) — this suite adds the
 * HTTP-layer companion: a Coach/Owner has no endpoint that could even
 * reach another member's tracking data, since every route here is
 * self-scoped to "me."
 */
final class PersonalTrackingControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE workout_log, body_metric, notification, announcement, pt_session, attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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

    private function createMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER);
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

    // ---- §7.1 Workout logging -------------------------------------------

    public function test_given_log_entry_submitted_when_saved_then_appears_immediately_newest_first(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');

        $first = $this->request('POST', '/members/me/workouts', $member, ['date' => '2026-08-01', 'type' => 'Run', 'durationMinutes' => 30]);
        self::assertSame(201, $first['status']);

        $second = $this->request('POST', '/members/me/workouts', $member, ['date' => '2026-08-05', 'type' => 'Swim', 'durationMinutes' => 45]);
        self::assertSame(201, $second['status']);

        $list = $this->request('GET', '/members/me/workouts', $member);

        self::assertSame(200, $list['status']);
        self::assertCount(2, $list['body']['workouts']);
        self::assertSame('Swim', $list['body']['workouts'][0]['type']); // newest (Aug 5) first
        self::assertSame('Run', $list['body']['workouts'][1]['type']);
    }

    public function test_workout_metrics_json_column_round_trips_arbitrary_shape(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/members/me/workouts', $member, [
            'type' => 'Strength',
            'durationMinutes' => 50,
            'metrics' => ['sets' => 5, 'reps' => 8, 'exercise' => 'Squat'],
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame(['sets' => 5, 'reps' => 8, 'exercise' => 'Squat'], $result['body']['metrics']);
    }

    public function test_missing_required_fields_returns_400(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/members/me/workouts', $member, ['type' => 'Run']);

        self::assertSame(400, $result['status']);
    }

    public function test_a_members_workouts_never_include_someone_elses(): void
    {
        $memberA = $this->createMember('Member A', 'a@example.com');
        $memberB = $this->createMember('Member B', 'b@example.com');
        $this->request('POST', '/members/me/workouts', $memberB, ['type' => 'Ride', 'durationMinutes' => 20]);

        $result = $this->request('GET', '/members/me/workouts', $memberA);

        self::assertSame(200, $result['status']);
        self::assertCount(0, $result['body']['workouts']);
    }

    // ---- §7.2 Body metrics & progress ------------------------------------

    public function test_given_no_entries_yet_when_listed_then_empty_array_not_error(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');

        $result = $this->request('GET', '/members/me/body-metrics', $member);

        self::assertSame(200, $result['status']);
        self::assertSame([], $result['body']['bodyMetrics']);
    }

    public function test_given_entries_logged_when_listed_then_chronological_for_the_trend_chart(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');
        $this->request('POST', '/members/me/body-metrics', $member, ['date' => '2026-08-05', 'weightKg' => '71.0']);
        $this->request('POST', '/members/me/body-metrics', $member, ['date' => '2026-08-01', 'weightKg' => '72.5']);

        $result = $this->request('GET', '/members/me/body-metrics', $member);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['bodyMetrics']);
        self::assertSame('2026-08-01', $result['body']['bodyMetrics'][0]['date']); // oldest first — chart plots left-to-right
        self::assertSame('2026-08-05', $result['body']['bodyMetrics'][1]['date']);
    }

    public function test_body_fat_pct_is_optional(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/members/me/body-metrics', $member, ['weightKg' => '70.0']);

        self::assertSame(201, $result['status']);
        self::assertNull($result['body']['bodyFatPct']);
    }

    public function test_missing_weight_returns_400(): void
    {
        $member = $this->createMember('Mia Member', 'mia@example.com');

        $result = $this->request('POST', '/members/me/body-metrics', $member, ['bodyFatPct' => '18.0']);

        self::assertSame(400, $result['status']);
    }

    // ---- Private by default (HTTP-layer companion to the Voter unit test) ----

    public function test_a_coach_has_no_endpoint_that_reaches_a_members_workouts(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('GET', '/members/me/workouts', $coach);

        // Self-scoped by design: a Coach has no MemberProfile, so there is
        // no member-shaped identity for this route to even resolve —
        // there is no "someone else's id" a Coach could pass instead.
        self::assertSame(404, $result['status']);
    }

    public function test_a_coach_has_no_endpoint_that_reaches_a_members_body_metrics(): void
    {
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);

        $result = $this->request('GET', '/members/me/body-metrics', $coach);

        self::assertSame(404, $result['status']);
    }
}

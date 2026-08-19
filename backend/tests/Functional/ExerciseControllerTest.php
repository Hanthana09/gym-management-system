<?php

namespace App\Tests\Functional;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * setly-phase-exercise-media.md §5 / claude-code-prompt-exercise-media.md
 * testing section: GET /exercises (list, Redis-cached, filtered) and
 * GET /exercises/{id} (detail), through the real HTTP + API Platform layer.
 */
final class ExerciseControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;
    private CacheItemPoolInterface $catalogCache;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->catalogCache = static::getContainer()->get('exercise_catalog.cache');
        $this->catalogCache->clear();
        $this->em->getConnection()->executeStatement(
            'TRUNCATE exercise_log, workout_schedule_exercise, exercise, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    private function createUser(string $name, string $email, UserRole $role): User
    {
        $user = new User($name, $email, null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistExercise(
        string $sourceId,
        string $name,
        array $primaryMuscles,
        array $secondaryMuscles = [],
        ?string $equipment = 'barbell',
        string $category = 'strength',
    ): Exercise {
        $exercise = new Exercise($sourceId, $name, strtolower(str_replace(' ', '-', $name)), 'beginner', $category);
        $exercise->update($name, strtolower(str_replace(' ', '-', $name)), 'push', 'beginner', 'compound', $equipment, $primaryMuscles, $secondaryMuscles, ['Step one.'], $category);
        // A real imported exercise always has a poster — null fields are
        // dropped from API Platform's JSON output entirely (not serialized
        // as null), so leaving this unset would make posterUrl vanish from
        // every response in these tests for reasons unrelated to what's
        // under test.
        $exercise->setImages($sourceId . '/poster.webp', [$sourceId . '/detail-0.webp']);
        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    private function authHeaders(User $user): array
    {
        $token = static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);

        return ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function get(string $uri, User $actingAs): array
    {
        // API Platform's own routing prepends /api on top of this entity's
        // routePrefix: '/api/v1' (same double-prefix already observed on
        // every other #[ApiResource] Get/GetCollection route in this
        // codebase — confirmed via `debug:router`).
        $this->client->request('GET', '/api/api/v1' . $uri, server: $this->authHeaders($actingAs));
        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
            'headers' => $response->headers,
        ];
    }

    public function test_a_coach_can_list_the_catalog(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $this->persistExercise('Bench_Press', 'Bench Press', ['chest'], ['triceps']);
        $this->persistExercise('Squat', 'Squat', ['quadriceps']);

        $result = $this->get('/exercises', $coach);

        self::assertSame(200, $result['status']);
        self::assertCount(2, $result['body']['member'] ?? $result['body']);
    }

    /** claude-code-prompt-exercise-media.md: "GET /exercises?muscle=biceps returns only exercises with biceps in primary_muscles or secondary_muscles." */
    public function test_the_muscle_filter_matches_primary_or_secondary_muscles(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $this->persistExercise('Bench_Press', 'Bench Press', ['chest'], ['triceps']);
        $this->persistExercise('Squat', 'Squat', ['quadriceps'], ['glutes']);
        $this->persistExercise('Tricep_Dip', 'Tricep Dip', ['triceps']);

        $primaryMatch = $this->get('/exercises?muscle=chest', $coach);
        $secondaryMatch = $this->get('/exercises?muscle=triceps', $coach);
        $noMatch = $this->get('/exercises?muscle=biceps', $coach);

        $namesOf = fn (array $result) => array_column($result['body']['member'] ?? $result['body'], 'name');
        self::assertSame(['Bench Press'], $namesOf($primaryMatch));
        self::assertEqualsCanonicalizing(['Bench Press', 'Tricep Dip'], $namesOf($secondaryMatch));
        self::assertSame([], $namesOf($noMatch));
    }

    public function test_the_equipment_and_category_filters_narrow_the_list(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $this->persistExercise('Bench_Press', 'Bench Press', ['chest'], equipment: 'barbell', category: 'strength');
        $this->persistExercise('Yoga_Stretch', 'Yoga Stretch', ['back'], equipment: null, category: 'stretching');

        $byEquipment = $this->get('/exercises?equipment=barbell', $coach);
        $byCategory = $this->get('/exercises?category=stretching', $coach);

        self::assertCount(1, $byEquipment['body']['member'] ?? $byEquipment['body']);
        self::assertCount(1, $byCategory['body']['member'] ?? $byCategory['body']);
    }

    /** claude-code-prompt-exercise-media.md: list response genuinely lacks detailImageUrls/instructions — not just empty. */
    public function test_the_list_response_never_includes_detail_only_fields(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $this->persistExercise('Bench_Press', 'Bench Press', ['chest']);

        $result = $this->get('/exercises', $coach);

        $row = ($result['body']['member'] ?? $result['body'])[0];
        self::assertArrayNotHasKey('detailImageUrls', $row);
        self::assertArrayNotHasKey('instructions', $row);
        self::assertArrayNotHasKey('primaryMuscles', $row);
        self::assertArrayHasKey('posterUrl', $row);
        self::assertArrayHasKey('category', $row);
    }

    public function test_the_detail_endpoint_includes_full_detail(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $exercise = $this->persistExercise('Bench_Press', 'Bench Press', ['chest'], ['triceps']);

        $result = $this->get('/exercises/' . $exercise->getId(), $coach);

        self::assertSame(200, $result['status']);
        self::assertSame(['chest'], $result['body']['primaryMuscles']);
        self::assertSame(['triceps'], $result['body']['secondaryMuscles']);
        self::assertSame(['Step one.'], $result['body']['instructions']);
        self::assertArrayHasKey('detailImageUrls', $result['body']);
    }

    public function test_a_member_cannot_browse_the_catalog_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $this->persistExercise('Bench_Press', 'Bench Press', ['chest']);

        self::assertSame(403, $this->get('/exercises', $member)['status']);
    }

    /** setly-phase-exercise-media.md §5: "invalidated only when the catalog is re-imported." */
    public function test_the_filtered_list_cache_reflects_a_new_exercise_only_after_the_cache_is_cleared(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $this->persistExercise('Bench_Press', 'Bench Press', ['chest']);

        $before = $this->get('/exercises?muscle=chest', $coach);
        self::assertCount(1, $before['body']['member'] ?? $before['body']);

        // A second matching exercise appears, but the cached id-list for
        // this exact filter combination is untouched — same guarantee
        // ImportExercisesCommand relies on when it clears the whole pool.
        $this->persistExercise('Incline_Bench_Press', 'Incline Bench Press', ['chest']);
        $stillCached = $this->get('/exercises?muscle=chest', $coach);
        self::assertCount(1, $stillCached['body']['member'] ?? $stillCached['body'], 'the id list is cached until explicitly cleared');

        $this->catalogCache->clear();
        $afterClear = $this->get('/exercises?muscle=chest', $coach);
        self::assertCount(2, $afterClear['body']['member'] ?? $afterClear['body']);
    }
}

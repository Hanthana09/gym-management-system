<?php

namespace App\Tests\Functional;

use App\Entity\Exercise;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * setly-phase-workout-scheduling.md's explicit testing section — both the
 * positive flow (schedule -> assign -> log; edit propagates; re-assign
 * replaces) and the six negative/403 cases, exercised end to end through
 * the real HTTP layer (WorkoutScheduleController, WorkoutAssignmentController,
 * ExerciseLogController, and their Voters together).
 */
final class WorkoutSchedulingControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE exercise_log, workout_assignment, workout_schedule_exercise, workout_schedule, exercise, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    /** Same lazy gym-provisioning pattern as PtSessionControllerTest::primaryBranch() — Coach-facing endpoints resolve "the gym" via findTheOnlyGym(), which stays null until one exists. */
    private function ensureGym(): Gym
    {
        $gym = $this->em->getRepository(Gym::class)->findOneBy([]);
        if ($gym === null) {
            $owner = new User('Olivia Owner', 'owner-' . bin2hex(random_bytes(4)) . '@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
            $this->em->persist($owner);
            $gym = new Gym("Olivia's Gym", '', $owner);
            $this->em->persist($gym);
            $this->em->flush();
        }

        return $gym;
    }

    private function createUser(string $name, string $email, UserRole $role): User
    {
        if ($role !== UserRole::OWNER) {
            $this->ensureGym();
        }
        $user = new User($name, $email, null, $role, UserStatus::ACTIVE);
        $this->em->persist($user);
        if ($role === UserRole::MEMBER) {
            // The assign flow's member picker sources from MemberProfile,
            // not a role-filtered User query (WorkoutAssignmentController::
            // members() docblock — same "not pickable without a real
            // profile" reasoning as CoachProfileRepository::findAllWithActiveUser()).
            $this->em->persist(new MemberProfile($user));
        }
        $this->em->flush();

        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);

        return ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            '/api' . $uri,
            server: array_merge(['CONTENT_TYPE' => 'application/json'], $this->authHeaders($actingAs)),
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        return $this->response();
    }

    private function response(): array
    {
        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * setly-phase-exercise-media.md: Exercise is a platform-wide reference
     * catalog, written only by ImportExercisesCommand — no coach-facing
     * create endpoint exists anymore (a hard exclusion of this phase), so
     * tests seed it the same way any other reference data gets seeded:
     * persisting the entity directly.
     */
    private function createExercise(string $name = 'Bench Press', string $primaryMuscle = 'chest'): string
    {
        $exercise = new Exercise('src-' . bin2hex(random_bytes(4)), $name, strtolower(str_replace(' ', '-', $name)), 'beginner', 'strength');
        $exercise->update($name, strtolower(str_replace(' ', '-', $name)), null, 'beginner', null, 'barbell', [$primaryMuscle], [], ['Step one.'], 'strength');
        $this->em->persist($exercise);
        $this->em->flush();

        return (string) $exercise->getId();
    }

    private function createSchedule(User $coach, string $name = 'Strength Block'): string
    {
        return $this->request('POST', '/workout-schedules', $coach, ['name' => $name, 'type' => 'strength'])['body']['id'];
    }

    private function addExerciseToSchedule(User $coach, string $scheduleId, string $exerciseId, int $sets = 3, int $reps = 10): array
    {
        return $this->request('POST', "/workout-schedules/{$scheduleId}/exercises", $coach, [
            'exerciseId' => $exerciseId, 'dayNumber' => 1, 'order' => 1, 'sets' => $sets, 'reps' => $reps,
        ]);
    }

    // ---- Positive: full loop --------------------------------------------

    public function test_given_a_coach_builds_a_schedule_and_assigns_it_when_the_member_logs_an_exercise_in_it_then_it_succeeds(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $exerciseId = $this->createExercise();
        $scheduleId = $this->createSchedule($coach);
        $this->addExerciseToSchedule($coach, $scheduleId, $exerciseId);

        $assignResult = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleId, 'memberId' => (string) $member->getId()]);
        self::assertSame(201, $assignResult['status']);
        $assignmentId = $assignResult['body']['id'];

        $memberList = $this->request('GET', '/workout-assignments?member=me&status=active', $member);
        self::assertCount(1, $memberList['body']['assignments']);
        self::assertSame($assignmentId, $memberList['body']['assignments'][0]['id']);

        $scopedExercises = $this->request('GET', "/workout-assignments/{$assignmentId}/exercises", $member);
        self::assertCount(1, $scopedExercises['body']['exercises']);

        $logResult = $this->request('POST', '/exercise-logs', $member, [
            'assignmentId' => $assignmentId, 'exerciseId' => $exerciseId, 'setsCompleted' => 3, 'repsCompleted' => 10, 'weight' => '60.00',
        ]);
        self::assertSame(201, $logResult['status']);
    }

    /** "Coach edits a schedule exercise's sets/reps -> assigned members see updated values without re-assignment." */
    public function test_given_an_assigned_schedule_when_the_coach_edits_a_line_items_sets_and_reps_then_the_member_sees_the_update_without_reassignment(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $exerciseId = $this->createExercise();
        $scheduleId = $this->createSchedule($coach);
        $line = $this->addExerciseToSchedule($coach, $scheduleId, $exerciseId, sets: 3, reps: 10)['body'];
        $assignmentId = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleId, 'memberId' => (string) $member->getId()])['body']['id'];

        $this->request('PATCH', "/workout-schedule-exercises/{$line['id']}", $coach, ['sets' => 5, 'reps' => 8]);

        $scopedExercises = $this->request('GET', "/workout-assignments/{$assignmentId}/exercises", $member);
        self::assertSame(5, $scopedExercises['body']['exercises'][0]['sets']);
        self::assertSame(8, $scopedExercises['body']['exercises'][0]['reps']);
    }

    /** "Coach assigns a second schedule to a member who already has an active one -> old assignment status becomes replaced, new one is active, old logs remain queryable under the old assignment_id." */
    public function test_given_a_member_already_has_an_active_assignment_when_the_coach_assigns_a_second_schedule_then_the_first_is_replaced_and_its_logs_remain_queryable(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $exerciseId = $this->createExercise();
        $scheduleA = $this->createSchedule($coach, 'Block A');
        $this->addExerciseToSchedule($coach, $scheduleA, $exerciseId);
        $scheduleB = $this->createSchedule($coach, 'Block B');
        $this->addExerciseToSchedule($coach, $scheduleB, $exerciseId);

        $firstAssignmentId = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleA, 'memberId' => (string) $member->getId()])['body']['id'];
        $this->request('POST', '/exercise-logs', $member, [
            'assignmentId' => $firstAssignmentId, 'exerciseId' => $exerciseId, 'setsCompleted' => 3, 'repsCompleted' => 10,
        ]);

        $secondResult = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleB, 'memberId' => (string) $member->getId()]);
        self::assertSame(201, $secondResult['status']);
        $secondAssignmentId = $secondResult['body']['id'];
        self::assertNotSame($firstAssignmentId, $secondAssignmentId);

        $activeOnly = $this->request('GET', '/workout-assignments?member=me&status=active', $member);
        self::assertCount(1, $activeOnly['body']['assignments']);
        self::assertSame($secondAssignmentId, $activeOnly['body']['assignments'][0]['id']);

        $oldLogs = $this->request('GET', "/workout-assignments/{$firstAssignmentId}/logs", $coach);
        self::assertCount(1, $oldLogs['body']['logs'], 'the replaced assignment\'s log must remain queryable under its own assignment_id');
    }

    /** Assign flow's member picker: any current gym member (no persisted coach-roster entity exists — see WorkoutAssignmentController::members() docblock). */
    public function test_the_member_picker_lists_the_gym_roster_for_a_coach(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        $result = $this->request('GET', '/workout-assignments/members', $coach);

        self::assertSame(200, $result['status']);
        self::assertContains($member->getName(), array_column($result['body']['members'], 'name'));
    }

    public function test_a_member_cannot_use_the_coachs_member_picker_403(): void
    {
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);

        self::assertSame(403, $this->request('GET', '/workout-assignments/members', $member)['status']);
    }

    /** The picker flags members who already have an active assignment from this coach, so the frontend can show the replace-confirmation before calling POST /workout-assignments. */
    public function test_the_member_picker_flags_a_member_who_already_has_an_active_assignment_from_this_coach(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $scheduleId = $this->createSchedule($coach);
        $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleId, 'memberId' => (string) $member->getId()]);

        $result = $this->request('GET', '/workout-assignments/members', $coach);

        $entry = array_values(array_filter($result['body']['members'], fn ($m) => $m['id'] === (string) $member->getId()))[0];
        self::assertTrue($entry['hasActiveAssignmentFromMe']);
    }

    // ---- Negative / 403 cases --------------------------------------------

    /** "Member attempts to log an exercise that exists in the global catalog but is not in their active schedule -> 403 via ExerciseLogVoter." */
    public function test_a_member_cannot_log_a_catalog_exercise_that_is_not_in_their_schedule_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $scheduledExerciseId = $this->createExercise('Bench Press', 'chest');
        $unscheduledExerciseId = $this->createExercise('Deadlift', 'back');
        $scheduleId = $this->createSchedule($coach);
        $this->addExerciseToSchedule($coach, $scheduleId, $scheduledExerciseId);
        $assignmentId = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleId, 'memberId' => (string) $member->getId()])['body']['id'];

        $result = $this->request('POST', '/exercise-logs', $member, [
            'assignmentId' => $assignmentId, 'exerciseId' => $unscheduledExerciseId, 'setsCompleted' => 3, 'repsCompleted' => 10,
        ]);

        self::assertSame(403, $result['status']);
    }

    /** "Member attempts to log against an assignment with status = replaced or completed -> 403." */
    public function test_a_member_cannot_log_against_a_replaced_assignment_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $exerciseId = $this->createExercise();
        $scheduleA = $this->createSchedule($coach, 'Block A');
        $this->addExerciseToSchedule($coach, $scheduleA, $exerciseId);
        $scheduleB = $this->createSchedule($coach, 'Block B');
        $this->addExerciseToSchedule($coach, $scheduleB, $exerciseId);
        $firstAssignmentId = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleA, 'memberId' => (string) $member->getId()])['body']['id'];
        $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleB, 'memberId' => (string) $member->getId()]);

        $result = $this->request('POST', '/exercise-logs', $member, [
            'assignmentId' => $firstAssignmentId, 'exerciseId' => $exerciseId, 'setsCompleted' => 3, 'repsCompleted' => 10,
        ]);

        self::assertSame(403, $result['status']);
    }

    /** "Member A attempts to log against Member B's assignment_id -> 403." */
    public function test_a_member_cannot_log_against_a_different_members_assignment_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $memberA = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $memberB = $this->createUser('Ben Member', 'ben@example.com', UserRole::MEMBER);
        $exerciseId = $this->createExercise();
        $scheduleId = $this->createSchedule($coach);
        $this->addExerciseToSchedule($coach, $scheduleId, $exerciseId);
        $assignmentId = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleId, 'memberId' => (string) $memberA->getId()])['body']['id'];

        $result = $this->request('POST', '/exercise-logs', $memberB, [
            'assignmentId' => $assignmentId, 'exerciseId' => $exerciseId, 'setsCompleted' => 3, 'repsCompleted' => 10,
        ]);

        self::assertSame(403, $result['status']);
    }

    /** "Coach attempts to assign a schedule belonging to a different gym's coach -> 403 (existing gym-scoping Voter)." Single-gym product: WorkoutScheduleVoter::MANAGE already denies any Coach who isn't the schedule's own author. */
    public function test_a_different_coach_cannot_assign_someone_elses_schedule_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $otherCoach = $this->createUser('Owen Coach', 'owen@example.com', UserRole::COACH);
        $member = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $scheduleId = $this->createSchedule($coach);

        $result = $this->request('POST', '/workout-assignments', $otherCoach, ['scheduleId' => $scheduleId, 'memberId' => (string) $member->getId()]);

        self::assertSame(403, $result['status']);
    }

    /** "Member requests GET /workout-assignments/{id}/exercises for an assignment that isn't theirs -> 403." */
    public function test_a_member_cannot_view_the_scoped_exercises_of_an_assignment_that_isnt_theirs_403(): void
    {
        $coach = $this->createUser('Cara Coach', 'coach@example.com', UserRole::COACH);
        $memberA = $this->createUser('Mia Member', 'mia@example.com', UserRole::MEMBER);
        $memberB = $this->createUser('Ben Member', 'ben@example.com', UserRole::MEMBER);
        $exerciseId = $this->createExercise();
        $scheduleId = $this->createSchedule($coach);
        $this->addExerciseToSchedule($coach, $scheduleId, $exerciseId);
        $assignmentId = $this->request('POST', '/workout-assignments', $coach, ['scheduleId' => $scheduleId, 'memberId' => (string) $memberA->getId()])['body']['id'];

        $result = $this->request('GET', "/workout-assignments/{$assignmentId}/exercises", $memberB);

        self::assertSame(403, $result['status']);
    }
}

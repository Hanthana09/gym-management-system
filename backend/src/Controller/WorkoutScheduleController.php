<?php

namespace App\Controller;

use App\Entity\Exercise;
use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutSchedule;
use App\Entity\WorkoutScheduleExercise;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\ExerciseRepository;
use App\Repository\GymRepository;
use App\Repository\WorkoutScheduleExerciseRepository;
use App\Repository\WorkoutScheduleRepository;
use App\Security\Voter\WorkoutScheduleVoter;
use App\WorkoutScheduling\WorkoutScheduleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** setly-phase-workout-scheduling.md §7's `/workout-schedules` and `/workout-schedule-exercises` endpoints. */
#[Route('/api')]
class WorkoutScheduleController extends AbstractController
{
    private const VALID_STATUSES = [WorkoutSchedule::STATUS_DRAFT, WorkoutSchedule::STATUS_ACTIVE, WorkoutSchedule::STATUS_ARCHIVED];

    public function __construct(
        private readonly WorkoutScheduleService $schedules,
        private readonly WorkoutScheduleRepository $scheduleRepository,
        private readonly WorkoutScheduleExerciseRepository $scheduleExerciseRepository,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
    ) {
    }

    /** Coach's own template list — the schedule builder's list view and the assign flow's schedule picker. */
    #[Route('/workout-schedules', name: 'workout_schedules_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::COACH) {
            return $this->forbidden();
        }

        $schedules = array_map(fn (WorkoutSchedule $s) => $this->serializeSchedule($s), $this->scheduleRepository->findByCoach($user));

        return new JsonResponse(['schedules' => $schedules]);
    }

    #[Route('/workout-schedules/{id}', name: 'workout_schedules_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $schedule = $this->scheduleRepository->find($id);
        if ($schedule === null) {
            return $this->notFound('Schedule not found.');
        }
        if (!$this->isGranted(WorkoutScheduleVoter::VIEW, $schedule)) {
            return $this->forbidden();
        }

        return new JsonResponse($this->serializeSchedule($schedule, withExercises: true));
    }

    #[Route('/workout-schedules', name: 'workout_schedules_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::COACH) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $name = trim((string) ($data['name'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        if ($name === '' || $type === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'name and type are required.'], 400);
        }

        $gym = $this->resolveGym();
        if ($gym === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'No gym found for this account.'], 400);
        }

        $schedule = $this->schedules->createSchedule($gym, $user, $name, $type);

        return new JsonResponse($this->serializeSchedule($schedule), 201);
    }

    #[Route('/workout-schedules/{id}', name: 'workout_schedules_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $schedule = $this->scheduleRepository->find($id);
        if ($schedule === null) {
            return $this->notFound('Schedule not found.');
        }
        if (!$this->isGranted(WorkoutScheduleVoter::MANAGE, $schedule)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $schedule->getName();
        $type = array_key_exists('type', $data) ? trim((string) $data['type']) : $schedule->getType();
        $status = array_key_exists('status', $data) ? (string) $data['status'] : $schedule->getStatus();

        if ($name === '' || $type === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'name and type are required.'], 400);
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'status must be one of: ' . implode(', ', self::VALID_STATUSES) . '.'], 400);
        }

        $this->schedules->updateSchedule($schedule, $name, $type, $status);

        return new JsonResponse($this->serializeSchedule($schedule));
    }

    #[Route('/workout-schedules/{id}/exercises', name: 'workout_schedules_add_exercise', methods: ['POST'])]
    public function addExercise(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $schedule = $this->scheduleRepository->find($id);
        if ($schedule === null) {
            return $this->notFound('Schedule not found.');
        }
        if (!$this->isGranted(WorkoutScheduleVoter::MANAGE, $schedule)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        [$exercise, $error] = $this->resolveExercise($data['exerciseId'] ?? null);
        if ($error !== null) {
            return $error;
        }

        $dayNumber = (int) ($data['dayNumber'] ?? 0);
        $order = (int) ($data['order'] ?? 0);
        $sets = (int) ($data['sets'] ?? 0);
        $reps = (int) ($data['reps'] ?? 0);
        if ($dayNumber < 1 || $sets <= 0 || $reps <= 0) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'dayNumber (>=1), sets, and reps (both positive) are required.'], 400);
        }
        $restSeconds = isset($data['restSeconds']) && $data['restSeconds'] !== null ? (int) $data['restSeconds'] : null;
        $notes = isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null;

        $line = $this->schedules->addExercise($schedule, $exercise, $dayNumber, $order, $sets, $reps, $restSeconds, $notes);

        return new JsonResponse($this->serializeLine($line), 201);
    }

    #[Route('/workout-schedule-exercises/{id}', name: 'workout_schedule_exercises_update', methods: ['PATCH'])]
    public function updateExercise(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $line = $this->scheduleExerciseRepository->find($id);
        if ($line === null) {
            return $this->notFound('Schedule exercise not found.');
        }
        if (!$this->isGranted(WorkoutScheduleVoter::MANAGE, $line->getSchedule())) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $dayNumber = array_key_exists('dayNumber', $data) ? (int) $data['dayNumber'] : $line->getDayNumber();
        $order = array_key_exists('order', $data) ? (int) $data['order'] : $line->getOrder();
        $sets = array_key_exists('sets', $data) ? (int) $data['sets'] : $line->getSets();
        $reps = array_key_exists('reps', $data) ? (int) $data['reps'] : $line->getReps();
        if ($dayNumber < 1 || $sets <= 0 || $reps <= 0) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'dayNumber (>=1), sets, and reps (both positive) are required.'], 400);
        }
        $restSeconds = array_key_exists('restSeconds', $data) ? ($data['restSeconds'] !== null ? (int) $data['restSeconds'] : null) : $line->getRestSeconds();
        $notes = array_key_exists('notes', $data) ? ($data['notes'] !== '' ? (string) $data['notes'] : null) : $line->getNotes();

        $this->schedules->updateExercise($line, $dayNumber, $order, $sets, $reps, $restSeconds, $notes);

        return new JsonResponse($this->serializeLine($line));
    }

    #[Route('/workout-schedule-exercises/{id}', name: 'workout_schedule_exercises_delete', methods: ['DELETE'])]
    public function deleteExercise(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $line = $this->scheduleExerciseRepository->find($id);
        if ($line === null) {
            return $this->notFound('Schedule exercise not found.');
        }
        if (!$this->isGranted(WorkoutScheduleVoter::MANAGE, $line->getSchedule())) {
            return $this->forbidden();
        }

        $this->schedules->removeExercise($line);

        return new JsonResponse(null, 204);
    }

    /**
     * setly-phase-exercise-media.md §2.2: Exercise is a platform-wide
     * catalog now, not gym-scoped — any real catalog row is valid for any
     * schedule, no gym-ownership check.
     *
     * @return array{0: ?Exercise, 1: ?JsonResponse}
     */
    private function resolveExercise(mixed $exerciseId): array
    {
        if ($exerciseId === null || $exerciseId === '') {
            return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'exerciseId is required.'], 400)];
        }
        $exercise = $this->exerciseRepository->find((string) $exerciseId);
        if ($exercise === null) {
            return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'exerciseId does not refer to a real exercise.'], 400)];
        }

        return [$exercise, null];
    }

    private function resolveGym(): ?Gym
    {
        $gym = $this->gyms->findTheOnlyGym();

        return $gym !== null ? $this->gymProvisioning->ensureGymForOwner($gym->getOwner()) : null;
    }

    private function serializeSchedule(WorkoutSchedule $schedule, bool $withExercises = false): array
    {
        $body = [
            'id' => (string) $schedule->getId(),
            'coachId' => (string) $schedule->getCoach()->getId(),
            'name' => $schedule->getName(),
            'type' => $schedule->getType(),
            'status' => $schedule->getStatus(),
            'createdAt' => $schedule->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $schedule->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withExercises) {
            $body['exercises'] = array_map(
                fn (WorkoutScheduleExercise $line) => $this->serializeLine($line),
                $this->scheduleExerciseRepository->findBySchedule($schedule),
            );
        }

        return $body;
    }

    private function serializeLine(WorkoutScheduleExercise $line): array
    {
        return [
            'id' => (string) $line->getId(),
            'scheduleId' => (string) $line->getSchedule()->getId(),
            'exerciseId' => (string) $line->getExercise()->getId(),
            'exerciseName' => $line->getExercise()->getName(),
            'dayNumber' => $line->getDayNumber(),
            'order' => $line->getOrder(),
            'sets' => $line->getSets(),
            'reps' => $line->getReps(),
            'restSeconds' => $line->getRestSeconds(),
            'notes' => $line->getNotes(),
        ];
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found', 'message' => $message], 404);
    }

    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }
}

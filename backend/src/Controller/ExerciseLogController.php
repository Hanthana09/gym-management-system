<?php

namespace App\Controller;

use App\Entity\ExerciseLog;
use App\Entity\User;
use App\Repository\ExerciseRepository;
use App\Repository\WorkoutAssignmentRepository;
use App\Security\Voter\ExerciseLogVoter;
use App\WorkoutScheduling\ExerciseLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** setly-phase-workout-scheduling.md §7: POST /exercise-logs, Voter-enforced by ExerciseLogVoter. */
#[Route('/api')]
class ExerciseLogController extends AbstractController
{
    public function __construct(
        private readonly ExerciseLogService $logs,
        private readonly WorkoutAssignmentRepository $assignments,
        private readonly ExerciseRepository $exercises,
    ) {
    }

    #[Route('/exercise-logs', name: 'exercise_logs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $assignmentId = (string) ($data['assignmentId'] ?? '');
        $exerciseId = (string) ($data['exerciseId'] ?? '');
        $setsCompleted = (int) ($data['setsCompleted'] ?? 0);
        $repsCompleted = (int) ($data['repsCompleted'] ?? 0);
        if ($assignmentId === '' || $exerciseId === '' || $setsCompleted <= 0 || $repsCompleted <= 0) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'assignmentId, exerciseId, and positive setsCompleted/repsCompleted are required.',
            ], 400);
        }
        $weight = isset($data['weight']) && $data['weight'] !== null && $data['weight'] !== '' ? (string) $data['weight'] : null;
        $notes = isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null;

        $assignment = $this->assignments->find($assignmentId);
        if ($assignment === null) {
            return $this->notFound('Assignment not found.');
        }
        $exercise = $this->exercises->find($exerciseId);
        if ($exercise === null) {
            return $this->notFound('Exercise not found.');
        }

        // ExerciseLogVoter checked against a not-yet-persisted candidate — same pattern as ExpenseController::create().
        $candidate = new ExerciseLog($assignment, $exercise, $assignment->getMember(), $setsCompleted, $repsCompleted, $weight, $notes);
        if (!$this->isGranted(ExerciseLogVoter::CREATE, $candidate)) {
            return new JsonResponse([
                'error' => 'forbidden',
                'message' => "This exercise isn't part of your current schedule.",
            ], 403);
        }

        $log = $this->logs->log($assignment, $exercise, $setsCompleted, $repsCompleted, $weight, $notes);

        return new JsonResponse($this->serialize($log), 201);
    }

    private function serialize(ExerciseLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
            'assignmentId' => (string) $log->getAssignment()->getId(),
            'exerciseId' => (string) $log->getExercise()->getId(),
            'exerciseName' => $log->getExercise()->getName(),
            'loggedAt' => $log->getLoggedAt()->format(\DateTimeInterface::ATOM),
            'setsCompleted' => $log->getSetsCompleted(),
            'repsCompleted' => $log->getRepsCompleted(),
            'weight' => $log->getWeight(),
            'notes' => $log->getNotes(),
        ];
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
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

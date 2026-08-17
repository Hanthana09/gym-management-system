<?php

namespace App\Controller;

use App\Entity\BodyMetric;
use App\Entity\User;
use App\Entity\WorkoutLog;
use App\PersonalTracking\PersonalTrackingService;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\PersonalTrackingVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class PersonalTrackingController extends AbstractController
{
    public function __construct(
        private readonly PersonalTrackingService $tracking,
        private readonly MemberProfileRepository $memberProfiles,
    ) {
    }

    // ---- Workouts (architecture doc §7: POST/GET /members/me/workouts) ----

    #[Route('/members/me/workouts', name: 'workouts_create', methods: ['POST'])]
    public function createWorkout(Request $request): JsonResponse
    {
        $member = $this->currentMemberProfile();
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $data = $this->decode($request);
        $type = trim((string) ($data['type'] ?? ''));
        $durationMinutes = (int) ($data['durationMinutes'] ?? 0);
        $metrics = isset($data['metrics']) && is_array($data['metrics']) ? $data['metrics'] : [];
        $date = isset($data['date']) ? new \DateTimeImmutable((string) $data['date']) : new \DateTimeImmutable('today');

        if ($type === '' || $durationMinutes <= 0) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'type and a positive durationMinutes are required.',
            ], 400);
        }

        // architecture doc §9.1's PersonalTrackingVoter::MANAGE expects an
        // actual subject; this candidate exercises the real check (a
        // Member always passes for themselves — nobody else ever does,
        // by design, since there's no Coach/Owner branch).
        $candidate = new WorkoutLog($member, $date, $type, $durationMinutes, $metrics);
        if (!$this->isGranted(PersonalTrackingVoter::MANAGE, $candidate)) {
            return $this->forbidden();
        }

        $log = $this->tracking->logWorkout($member, $date, $type, $durationMinutes, $metrics);

        return new JsonResponse($this->serializeWorkout($log), 201);
    }

    #[Route('/members/me/workouts', name: 'workouts_list', methods: ['GET'])]
    public function listWorkouts(): JsonResponse
    {
        $member = $this->currentMemberProfile();
        if ($member instanceof JsonResponse) {
            return $member;
        }

        return new JsonResponse([
            'workouts' => array_map(fn (WorkoutLog $log) => $this->serializeWorkout($log), $this->tracking->listWorkouts($member)),
        ]);
    }

    // ---- Body metrics (architecture doc §7 names only GET; POST is added here —
    // §6.5 calls this "Member-only CRUD on WORKOUT_LOG and BODY_METRIC," and
    // without a way to create one the progress chart could never have data) ----

    #[Route('/members/me/body-metrics', name: 'body_metrics_create', methods: ['POST'])]
    public function createBodyMetric(Request $request): JsonResponse
    {
        $member = $this->currentMemberProfile();
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $data = $this->decode($request);
        $weightKg = array_key_exists('weightKg', $data) ? (string) $data['weightKg'] : '';
        $bodyFatPct = isset($data['bodyFatPct']) && $data['bodyFatPct'] !== '' ? (string) $data['bodyFatPct'] : null;
        $date = isset($data['date']) ? new \DateTimeImmutable((string) $data['date']) : new \DateTimeImmutable('today');

        if ($weightKg === '' || !is_numeric($weightKg)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'weightKg is required.'], 400);
        }

        $candidate = new BodyMetric($member, $date, $weightKg, $bodyFatPct);
        if (!$this->isGranted(PersonalTrackingVoter::MANAGE, $candidate)) {
            return $this->forbidden();
        }

        $metric = $this->tracking->recordBodyMetric($member, $date, $weightKg, $bodyFatPct);

        return new JsonResponse($this->serializeBodyMetric($metric), 201);
    }

    #[Route('/members/me/body-metrics', name: 'body_metrics_list', methods: ['GET'])]
    public function listBodyMetrics(): JsonResponse
    {
        $member = $this->currentMemberProfile();
        if ($member instanceof JsonResponse) {
            return $member;
        }

        return new JsonResponse([
            'bodyMetrics' => array_map(fn (BodyMetric $metric) => $this->serializeBodyMetric($metric), $this->tracking->listBodyMetrics($member)),
        ]);
    }

    // ---- helpers -------------------------------------------------------

    private function currentMemberProfile(): \App\Entity\MemberProfile|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        return $member;
    }

    private function serializeWorkout(WorkoutLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
            'date' => $log->getDate()->format('Y-m-d'),
            'type' => $log->getType(),
            'durationMinutes' => $log->getDurationMinutes(),
            'metrics' => $log->getMetrics(),
        ];
    }

    private function serializeBodyMetric(BodyMetric $metric): array
    {
        return [
            'id' => (string) $metric->getId(),
            'date' => $metric->getDate()->format('Y-m-d'),
            'weightKg' => $metric->getWeightKg(),
            'bodyFatPct' => $metric->getBodyFatPct(),
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

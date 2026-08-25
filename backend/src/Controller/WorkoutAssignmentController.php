<?php

namespace App\Controller;

use App\Entity\ExerciseLog;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Entity\WorkoutScheduleExercise;
use App\Enum\UserRole;
use App\Repository\ExerciseLogRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\UserRepository;
use App\Repository\WorkoutAssignmentRepository;
use App\Repository\WorkoutScheduleExerciseRepository;
use App\Repository\WorkoutScheduleRepository;
use App\Security\Voter\WorkoutAssignmentVoter;
use App\Security\Voter\WorkoutScheduleVoter;
use App\WorkoutScheduling\AssignmentConflictException;
use App\WorkoutScheduling\WorkoutAssignmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** setly-phase-workout-scheduling.md §7's `/workout-assignments` endpoints. */
#[Route('/api')]
class WorkoutAssignmentController extends AbstractController
{
    private const VALID_LIST_STATUSES = [
        WorkoutAssignment::STATUS_ACTIVE,
        WorkoutAssignment::STATUS_REPLACED,
        WorkoutAssignment::STATUS_COMPLETED,
        WorkoutAssignment::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly WorkoutAssignmentService $assignmentService,
        private readonly WorkoutAssignmentRepository $assignmentRepository,
        private readonly WorkoutScheduleRepository $scheduleRepository,
        private readonly WorkoutScheduleExerciseRepository $scheduleExerciseRepository,
        private readonly ExerciseLogRepository $exerciseLogRepository,
        private readonly UserRepository $users,
        private readonly MemberProfileRepository $memberProfiles,
    ) {
    }

    /**
     * Frontend assign-flow's member picker. Decision: a Coach may assign a
     * schedule to any current gym member, not just their existing PT
     * clients (MemberProfile::hasCoach()/findClientsOfCoach() both define
     * "client" as "has had a PT session with this coach" — too narrow
     * here, since assigning a workout is often how a coach-member
     * relationship *starts*, not something that presupposes one already
     * exists), and this is a single-gym product, so the full member
     * roster is the correct picker source.
     */
    #[Route('/workout-assignments/members', name: 'workout_assignments_members', methods: ['GET'])]
    public function members(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::COACH) {
            return $this->forbidden();
        }

        $members = array_map(
            fn (MemberProfile $profile) => [
                'id' => (string) $profile->getUser()->getId(),
                'name' => $profile->getUser()->getName(),
                // Assign flow's "this will replace their current schedule"
                // confirmation (setly-phase-workout-scheduling.md's
                // frontend task #2) — computed here so the confirm step
                // never needs a second round trip per candidate member.
                'hasActiveAssignmentFromMe' => $this->assignmentRepository->findActiveForCoachAndMember($user, $profile->getUser()) !== null,
            ],
            $this->memberProfiles->findAllWithUser(),
        );

        return new JsonResponse(['members' => $members]);
    }

    /**
     * setly-phase-workout-scheduling.md §2.2/§4: assigns (or, if the
     * coach already has an active assignment with this member, replaces)
     * a schedule. "Different gym's coach" is denied by
     * WorkoutScheduleVoter::MANAGE below — in this single-gym product,
     * that Voter already denies any Coach who isn't the schedule's owner.
     */
    #[Route('/workout-assignments', name: 'workout_assignments_create', methods: ['POST'])]
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
        $scheduleId = (string) ($data['scheduleId'] ?? '');
        $memberId = (string) ($data['memberId'] ?? '');
        if ($scheduleId === '' || $memberId === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'scheduleId and memberId are required.'], 400);
        }

        $schedule = $this->scheduleRepository->find($scheduleId);
        if ($schedule === null) {
            return $this->notFound('Schedule not found.');
        }
        if (!$this->isGranted(WorkoutScheduleVoter::MANAGE, $schedule)) {
            return $this->forbidden();
        }

        $member = $this->users->find($memberId);
        if ($member === null || $member->getRole() !== UserRole::MEMBER) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'memberId does not refer to a real member.'], 400);
        }

        try {
            $assignment = $this->assignmentService->assign($schedule, $member, $user);
        } catch (AssignmentConflictException $exception) {
            return new JsonResponse(['error' => 'assignment_conflict', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serializeAssignment($assignment), 201);
    }

    /** setly-phase-workout-scheduling.md §7: GET /workout-assignments?member=me&status=active — the Member's own list. */
    #[Route('/workout-assignments', name: 'workout_assignments_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($request->query->get('member') !== 'me') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => "Only ?member=me is supported."], 400);
        }
        if ($user->getRole() !== UserRole::MEMBER) {
            return $this->forbidden();
        }

        $status = $request->query->get('status');
        if ($status !== null && !in_array($status, self::VALID_LIST_STATUSES, true)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'status must be one of: ' . implode(', ', self::VALID_LIST_STATUSES) . '.'], 400);
        }

        $assignments = array_map(
            fn (WorkoutAssignment $a) => $this->serializeAssignment($a),
            $this->assignmentRepository->findByMemberAndStatus($user, $status),
        );

        return new JsonResponse(['assignments' => $assignments]);
    }

    /**
     * setly-phase-workout-scheduling.md §7: scoped to this assignment's
     * schedule only — never the global catalog. Hard exclusion list:
     * no endpoint here ever falls back to the unscoped Exercise catalog.
     */
    #[Route('/workout-assignments/{id}/exercises', name: 'workout_assignments_exercises', methods: ['GET'])]
    public function exercises(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $assignment = $this->assignmentRepository->find($id);
        if ($assignment === null) {
            return $this->notFound('Assignment not found.');
        }
        if (!$this->isGranted(WorkoutAssignmentVoter::VIEW, $assignment)) {
            return $this->forbidden();
        }

        $lines = $this->scheduleExerciseRepository->findByScheduleWithFilters(
            $assignment->getSchedule(),
            $request->query->get('muscle'),
            $request->query->get('equipment'),
        );

        return new JsonResponse(['exercises' => array_map(fn (WorkoutScheduleExercise $l) => $this->serializeLine($l), $lines)]);
    }

    #[Route('/workout-assignments/{id}/logs', name: 'workout_assignments_logs', methods: ['GET'])]
    public function logs(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $assignment = $this->assignmentRepository->find($id);
        if ($assignment === null) {
            return $this->notFound('Assignment not found.');
        }
        if (!$this->isGranted(WorkoutAssignmentVoter::VIEW, $assignment)) {
            return $this->forbidden();
        }

        $logs = array_map(fn (ExerciseLog $l) => $this->serializeLog($l), $this->exerciseLogRepository->findByAssignment($assignment));

        return new JsonResponse(['logs' => $logs]);
    }

    private function serializeAssignment(WorkoutAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->getId(),
            'scheduleId' => (string) $assignment->getSchedule()->getId(),
            'scheduleName' => $assignment->getSchedule()->getName(),
            'memberId' => (string) $assignment->getMember()->getId(),
            'coachId' => (string) $assignment->getCoach()->getId(),
            'coachName' => $assignment->getCoach()->getName(),
            'status' => $assignment->getStatus(),
            'startDate' => $assignment->getStartDate()->format('Y-m-d'),
            'assignedAt' => $assignment->getAssignedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeLine(WorkoutScheduleExercise $line): array
    {
        return [
            'id' => (string) $line->getId(),
            'exerciseId' => (string) $line->getExercise()->getId(),
            'exerciseName' => $line->getExercise()->getName(),
            'primaryMuscles' => $line->getExercise()->getPrimaryMuscles(),
            'equipment' => $line->getExercise()->getEquipment(),
            'posterUrl' => $line->getExercise()->getPosterUrl(),
            'dayNumber' => $line->getDayNumber(),
            'order' => $line->getOrder(),
            'sets' => $line->getSets(),
            'reps' => $line->getReps(),
            'restSeconds' => $line->getRestSeconds(),
            'notes' => $line->getNotes(),
        ];
    }

    private function serializeLog(ExerciseLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
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

<?php

namespace App\Controller;

use App\Branch\BranchResolver;
use App\Entity\CoachProfile;
use App\Entity\PtSession;
use App\Entity\User;
use App\Enum\UserRole;
use App\PersonalTraining\PtSessionConflictException;
use App\PersonalTraining\PtSessionService;
use App\Repository\CoachProfileRepository;
use App\Repository\GymRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\PtSessionRepository;
use App\Security\Voter\PtSessionVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PtSessionController extends AbstractController
{
    public function __construct(
        private readonly PtSessionService $sessions,
        private readonly PtSessionRepository $sessionRepository,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly GymRepository $gyms,
        private readonly BranchResolver $branches,
    ) {
    }

    /**
     * roadmap Phase 6 / functional requirements §5.1: coach picker source.
     * Sourced from CoachProfile (not a role-filtered User query) so a
     * coach User row without a profile — possible if it was ever seeded
     * outside the invite/approve flow — never appears pickable only to
     * 404 on every subsequent action (see CoachProfileRepository::findAllWithActiveUser).
     */
    #[Route('/coaches', name: 'coaches_list', methods: ['GET'])]
    public function listCoaches(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        // functional requirements §14.3: filtered to coaches assigned to
        // the branch the Member is booking at — same default-to-primary
        // as /pt-sessions itself, so a single-branch gym's picker is
        // unchanged (everyone's backfilled onto the one branch anyway).
        $gym = $this->gyms->findTheOnlyGym();
        $branch = $gym !== null ? $this->branches->resolve($gym, $request->query->get('branchId')) : null;

        $coaches = array_map(fn (CoachProfile $coach) => [
            'id' => (string) $coach->getUser()->getId(),
            'name' => $coach->getUser()->getName(),
        ], $this->coachProfiles->findAllWithActiveUser($branch));

        return new JsonResponse(['coaches' => $coaches]);
    }

    /** architecture doc §7: GET /coaches/:id/schedule (Coach — own; Owner — any). */
    #[Route('/coaches/{id}/schedule', name: 'coaches_schedule', methods: ['GET'])]
    public function coachSchedule(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $coach = $this->coachProfiles->find($id);
        if ($coach === null) {
            return $this->notFound('Coach not found.');
        }

        // No dedicated "coach schedule" Voter attribute exists (architecture
        // doc §9.1 only defines REQUEST/RESPOND/VIEW on PT_SESSION itself) —
        // Owner-any / Coach-own-only is exactly PT_SESSION_VIEW's rule, so a
        // throwaway PtSession candidate (coach + any member the coach has a
        // session with, or a blank one if they have none yet) exercises it.
        // The simplest correct check here is the same identity test the
        // Voter applies: Owner always, or this coach viewing their own id.
        if (!$this->isOwner($user) && !($this->isCoach($user) && $coach->getUser() === $user)) {
            return $this->forbidden();
        }

        $sessions = array_map(
            fn (PtSession $session) => $this->serialize($session, viewer: $user),
            $this->sessionRepository->findForCoach($coach),
        );

        return new JsonResponse(['sessions' => $sessions]);
    }

    /** Member's own "My sessions" list. */
    #[Route('/pt-sessions/me', name: 'pt_sessions_me', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        $sessions = array_map(
            fn (PtSession $session) => $this->serialize($session, viewer: $user),
            $this->sessionRepository->findForMember($member),
        );

        return new JsonResponse(['sessions' => $sessions]);
    }

    /** functional requirements §14.3: a Member can pick any branch where at least one Coach is assigned — not just their own enrolling branch. branchId defaults to the primary branch (single-branch gyms need no change at all). */
    #[Route('/pt-sessions', name: 'pt_sessions_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        $data = $this->decode($request);
        $coachUserId = (string) ($data['coachUserId'] ?? '');
        $scheduledAtRaw = (string) ($data['scheduledAt'] ?? '');
        $durationMinutes = (int) ($data['durationMinutes'] ?? 0);

        if ($coachUserId === '' || $scheduledAtRaw === '' || $durationMinutes <= 0) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'coachUserId, scheduledAt, and a positive durationMinutes are required.',
            ], 400);
        }

        $coach = $this->coachProfiles->find($coachUserId);
        if ($coach === null) {
            return $this->notFound('Coach not found.');
        }

        $gym = $this->gyms->findTheOnlyGym();
        $branch = $gym !== null ? $this->branches->resolve($gym, $data['branchId'] ?? null) : null;
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not belong to this gym.'], 400);
        }

        // A session referencing a branch the Coach isn't assigned to would
        // leave PtSessionVoter::RESPOND permanently unsatisfiable for them
        // (see that Voter's own docblock) — reject it here, at the one
        // place a session's branch is actually chosen, rather than let a
        // Coach discover it only when trying to respond.
        if (!$coach->getUser()->getBranchAssignments()->exists(fn ($k, $a) => $a->getBranch() === $branch)) {
            return new JsonResponse([
                'error' => 'coach_not_at_branch',
                'message' => 'This coach is not assigned to the selected branch.',
            ], 400);
        }

        try {
            $scheduledAt = new \DateTimeImmutable($scheduledAtRaw);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'scheduledAt must be a valid date/time.'], 400);
        }

        // architecture doc §9.1's PtSessionVoter::REQUEST expects an actual
        // PtSession subject; this candidate exercises the real check (a
        // Member always passes for themselves, anyone else always fails).
        $candidate = new PtSession($coach, $member, $branch, $scheduledAt, $durationMinutes);
        if (!$this->isGranted(PtSessionVoter::REQUEST, $candidate)) {
            return $this->forbidden();
        }

        $session = $this->sessions->request($member, $coach, $branch, $scheduledAt, $durationMinutes);

        return new JsonResponse($this->serialize($session, viewer: $user), 201);
    }

    /**
     * architecture doc §7: PATCH /pt-sessions/:id/status. One endpoint,
     * two actors: a Coach may set confirmed|declined on their own session
     * (PT_SESSION_RESPOND); a Member may set cancelled on their own
     * still-pending request (functional requirements §5.1) — authorized by
     * PT_SESSION_REQUEST, the same "this session belongs to this member"
     * check REQUEST already expresses, since the Voter (copied verbatim
     * from §9.1) has no separate CANCEL attribute.
     */
    #[Route('/pt-sessions/{id}/status', name: 'pt_sessions_status', methods: ['PATCH'])]
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $session = $this->sessionRepository->find($id);
        if ($session === null) {
            return $this->notFound('Session not found.');
        }

        $data = $this->decode($request);
        $status = (string) ($data['status'] ?? '');

        $action = match ($status) {
            'confirmed' => fn () => $this->respondAsCoach($session, accept: true),
            'declined' => fn () => $this->respondAsCoach($session, accept: false),
            'cancelled' => fn () => $this->respondAsMember($session),
            default => null,
        };

        if ($action === null) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'status must be one of: confirmed, declined, cancelled.',
            ], 400);
        }

        $forbidden = $action();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        return new JsonResponse($this->serialize($session, viewer: $user));
    }

    /** functional requirements §5.3: Coach-only, notes never returned to the Member (see serialize()). */
    #[Route('/pt-sessions/{id}/notes', name: 'pt_sessions_notes', methods: ['PATCH'])]
    public function updateNotes(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $session = $this->sessionRepository->find($id);
        if ($session === null) {
            return $this->notFound('Session not found.');
        }

        if (!$this->isGranted(PtSessionVoter::RESPOND, $session)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $notes = trim((string) ($data['notes'] ?? ''));

        $this->sessions->setNotes($session, $notes);

        return new JsonResponse($this->serialize($session, viewer: $user));
    }

    private function respondAsCoach(PtSession $session, bool $accept): ?JsonResponse
    {
        if (!$this->isGranted(PtSessionVoter::RESPOND, $session)) {
            return $this->forbidden();
        }

        try {
            $accept ? $this->sessions->accept($session) : $this->sessions->decline($session);
        } catch (PtSessionConflictException $exception) {
            return $this->conflict($exception);
        }

        return null;
    }

    private function respondAsMember(PtSession $session): ?JsonResponse
    {
        if (!$this->isGranted(PtSessionVoter::REQUEST, $session)) {
            return $this->forbidden();
        }

        try {
            $this->sessions->cancel($session);
        } catch (PtSessionConflictException $exception) {
            return $this->conflict($exception);
        }

        return null;
    }

    private function isOwner(User $user): bool
    {
        return $user->getRole() === UserRole::OWNER;
    }

    private function isCoach(User $user): bool
    {
        return $user->getRole() === UserRole::COACH;
    }

    /**
     * functional requirements §5.3: notes are visible only to the Coach
     * who owns the session — not the Member, and (conservatively, pending
     * the open decision flagged in architecture doc §9) not the Owner
     * either, since nothing in this phase's scope asks for an Owner-facing
     * view of them.
     */
    private function serialize(PtSession $session, User $viewer): array
    {
        $isOwningCoach = $viewer->getRole() === UserRole::COACH && $session->getCoach()->getUser() === $viewer;

        return [
            'id' => (string) $session->getId(),
            'coach' => ['id' => (string) $session->getCoach()->getUser()->getId(), 'name' => $session->getCoach()->getUser()->getName()],
            'member' => ['id' => (string) $session->getMember()->getUser()->getId(), 'name' => $session->getMember()->getUser()->getName()],
            'branchId' => (string) $session->getBranch()->getId(),
            'scheduledAt' => $session->getScheduledAt()->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $session->getDurationMinutes(),
            'status' => $session->getStatus()->value,
            'notes' => $isOwningCoach ? $session->getNotes() : null,
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

    private function conflict(PtSessionConflictException $exception): JsonResponse
    {
        return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
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

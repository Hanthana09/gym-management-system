<?php

namespace App\Controller;

use App\Attendance\AttendanceService;
use App\Attendance\CheckInBlockedException;
use App\Attendance\NoActiveSessionException;
use App\Branch\BranchResolver;
use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Enum\UserRole;
use App\Repository\AttendanceLogRepository;
use App\Repository\GymRepository;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\AttendanceVoter;
use App\Security\Voter\MemberVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AttendanceController extends AbstractController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly GymRepository $gyms,
        private readonly BranchResolver $branches,
    ) {
    }

    /** architecture doc §7: "branch selected by the member, e.g. via QR at that location" — branchId in the body, defaulting to the primary branch (functional requirements §14.1: a single-branch gym's Member never needs to think about this). */
    #[Route('/members/me/checkin', name: 'members_me_checkin', methods: ['POST'])]
    public function checkIn(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        if (!$this->isGranted(AttendanceVoter::CHECK_IN, $member)) {
            return $this->forbidden();
        }

        $branch = $this->resolveBranch($this->decode($request)['branchId'] ?? null);
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not belong to this gym.'], 400);
        }

        try {
            $log = $this->attendance->checkIn($member, $branch);
        } catch (CheckInBlockedException $exception) {
            return new JsonResponse([
                'error' => 'checkin_blocked',
                'reason' => $exception->reason->value,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return new JsonResponse($this->serializeLog($log), 201);
    }

    /**
     * Check-in-timer feature's minimal check-out mutation: self only — no
     * front-desk variant (Owner/Staff acting on a member's behalf), unlike
     * checkIn. Freezes the member's own top-bar timer; also pushed to any
     * other open tab/device via Mercure (AttendanceMercurePublisher).
     */
    #[Route('/members/me/checkout', name: 'members_me_checkout', methods: ['POST'])]
    public function checkOut(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        if (!$this->isGranted(AttendanceVoter::CHECK_OUT, $member)) {
            return $this->forbidden();
        }

        try {
            $log = $this->attendance->checkOut($member);
        } catch (NoActiveSessionException $exception) {
            return new JsonResponse(['error' => 'no_active_session', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serializeLog($log));
    }

    /**
     * Check-in-timer feature: what the Member top bar fetches on mount
     * (refresh-safe — always recomputed from the DB row, never trusted
     * from client-held state), then keeps live via the same Mercure topic
     * this feature's publisher writes to. Gated by MemberVoter::VIEW
     * (reused as-is) so a Member can only ever fetch their own — the same
     * Voter that already scopes the member list/roster.
     */
    #[Route('/members/{id}/attendance/active', name: 'members_attendance_active', methods: ['GET'])]
    public function activeAttendance(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        if (!$this->isGranted(MemberVoter::VIEW, $member)) {
            return $this->forbidden();
        }

        $log = $this->attendanceLogs->findOpenForMember($member);

        return new JsonResponse(['attendance' => $log !== null ? [
            'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
            'checkOutAt' => $log->getCheckOut()?->format(\DateTimeInterface::ATOM),
        ] : null]);
    }

    /**
     * architecture doc §7: front-desk variant — Owner or Staff checking a
     * member in on their behalf (roadmap Phase 15.1, branch-scoped as of
     * Phase 16). AttendanceVoter::CHECK_IN itself stays permissive on
     * WHICH member (the hub model, per its own docblock) — the branch
     * eligibility check below is a separate, controller-level concern for
     * Staff specifically: which branch THEY'RE allowed to record a
     * check-in at, not a restriction on the member being checked in.
     */
    #[Route('/members/{id}/checkin', name: 'members_checkin_front_desk', methods: ['POST'])]
    public function checkInFrontDesk(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        if (!$this->isGranted(AttendanceVoter::CHECK_IN, $member)) {
            return $this->forbidden();
        }

        $branchIdParam = $this->decode($request)['branchId'] ?? null;
        $branch = $user->getRole() === UserRole::STAFF
            ? $this->resolveStaffBranch($user, $branchIdParam)
            : $this->resolveBranch($branchIdParam);

        if ($branch instanceof JsonResponse) {
            return $branch;
        }
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not belong to this gym.'], 400);
        }

        try {
            $log = $this->attendance->checkIn($member, $branch, CheckInMethod::FRONT_DESK);
        } catch (CheckInBlockedException $exception) {
            return new JsonResponse([
                'error' => 'checkin_blocked',
                'reason' => $exception->reason->value,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return new JsonResponse($this->serializeLog($log), 201);
    }

    private function resolveBranch(?string $branchId): ?Branch
    {
        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null) {
            return null;
        }

        return $this->branches->resolve($gym, $branchId);
    }

    /**
     * functional requirements §14.2: Staff can only front-desk check in at
     * a branch they're assigned to — enforced here (API level), not just
     * hidden from the UI. Omitting branchId defaults to Staff's first
     * assignment; an explicit branchId outside their assignments is
     * rejected outright, same as if they weren't assigned anywhere.
     */
    private function resolveStaffBranch(User $staff, ?string $branchId): Branch|JsonResponse|null
    {
        $assigned = array_map(fn ($a) => $a->getBranch(), $staff->getBranchAssignments()->toArray());

        if ($branchId === null || $branchId === '') {
            if ($assigned === []) {
                return new JsonResponse([
                    'error' => 'no_branch_assignment',
                    'message' => 'You are not assigned to any branch yet — ask the Owner to assign you one.',
                ], 403);
            }

            return $assigned[0];
        }

        foreach ($assigned as $branch) {
            if ((string) $branch->getId() === $branchId) {
                return $branch;
            }
        }

        return new JsonResponse(['error' => 'forbidden', 'message' => 'You are not assigned to that branch.'], 403);
    }

    private function serializeLog(AttendanceLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
            'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
            'checkOutAt' => $log->getCheckOut()?->format(\DateTimeInterface::ATOM),
            'method' => $log->getMethod()->value,
            'branchId' => (string) $log->getBranch()->getId(),
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
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return [];
        }
    }
}

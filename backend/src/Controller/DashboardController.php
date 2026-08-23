<?php

namespace App\Controller;

use App\Entity\Branch;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Entity\User;
use App\Gym\GymProvisioningService;
use App\Repository\AttendanceLogRepository;
use App\Repository\BranchRepository;
use App\Repository\CoachProfileRepository;
use App\Repository\GymRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationRepository;
use App\Repository\PtSessionRepository;
use App\Security\Voter\DashboardVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * gym-management-dashboard-redesign.md: the four role home-view
 * endpoints. Owner reuses the exact same underlying repository calls
 * ReportController's `/reports/dashboard` already uses (no behavior
 * change, per Phase 3 point 1) — this is a new, additive endpoint
 * alongside it, not a replacement; `/reports/*` still backs the deeper
 * Reports tabs on OwnerDashboardPage. Staff/Coach/Member dashboards are
 * genuinely new — no prior endpoint existed for them.
 */
#[Route('/api/v1/dashboard')]
class DashboardController extends AbstractController
{
    private const EXPIRING_WINDOW_DAYS = 7;
    private const RECENT_ACTIVITY_LIMIT = 10;
    // No "capacity" concept exists anywhere in this data model — this is a
    // presentational, documented approximation for the utilization bar
    // only, never used for any permission/business decision.
    private const NOMINAL_WEEKLY_SESSION_CAPACITY = 20;

    public function __construct(
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
        private readonly BranchRepository $branches,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly MembershipRepository $memberships,
        private readonly InvoiceRepository $invoices,
        private readonly PtSessionRepository $ptSessions,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly NotificationRepository $notifications,
    ) {
    }

    #[Route('/owner', name: 'dashboard_owner', methods: ['GET'])]
    public function owner(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(DashboardVoter::VIEW_OWNER, $gym)) {
            return $this->forbidden();
        }

        [$branch, $error] = $this->resolveOwnerBranch($gym, $request);
        if ($error !== null) {
            return $error;
        }

        $today = new \DateTimeImmutable('today');

        return new JsonResponse([
            'branchId' => $branch?->getId() !== null ? (string) $branch->getId() : null,
            'todayCheckins' => $this->attendanceLogs->countSince($today, $branch),
            'todayRevenue' => $this->invoices->sumPaidAmountOnDate($today, $branch),
            'activeMembersCount' => $this->memberships->countWithinTermOnDate($today, $branch),
            'unreadNotificationCount' => $this->notifications->countUnreadForUser($user),
        ]);
    }

    #[Route('/staff', name: 'dashboard_staff', methods: ['GET'])]
    public function staff(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null || !$this->isGranted(DashboardVoter::VIEW_STAFF, $gym)) {
            return $this->forbidden();
        }

        [$branch, $error] = $this->resolveScopedBranch($user, $request);
        if ($error !== null) {
            return $error;
        }

        $today = new \DateTimeImmutable('today');
        $expiring = $this->memberships->findActiveExpiringWithinDays(self::EXPIRING_WINDOW_DAYS, $branch);

        return new JsonResponse([
            'branchId' => (string) $branch->getId(),
            'todayCheckins' => $this->attendanceLogs->countSince($today, $branch),
            'expiringMembershipsCount' => count($expiring),
            'expiringMemberships' => array_map(fn ($m) => [
                'memberId' => (string) $m->getMember()->getUser()->getId(),
                'memberName' => $m->getMember()->getUser()->getName(),
                'endDate' => $m->getEndDate()->format('Y-m-d'),
            ], array_slice($expiring, 0, self::RECENT_ACTIVITY_LIMIT)),
            'recentActivity' => array_map(fn ($log) => [
                'id' => (string) $log->getId(),
                'memberName' => $log->getMember()->getUser()->getName(),
                'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
            ], $this->attendanceLogs->findByDateRange($today, $today->modify('+1 day'), $branch)),
            'unreadNotificationCount' => $this->notifications->countUnreadForUser($user),
        ]);
    }

    #[Route('/coach', name: 'dashboard_coach', methods: ['GET'])]
    public function coach(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null || !$this->isGranted(DashboardVoter::VIEW_COACH, $gym)) {
            return $this->forbidden();
        }

        $coachProfile = $this->coachProfiles->findOneByUser($user);
        if ($coachProfile === null) {
            return $this->notFound('No coach profile found for this account.');
        }

        [$branch, $error] = $this->resolveScopedBranch($user, $request);
        if ($error !== null) {
            return $error;
        }

        $today = new \DateTimeImmutable('today');
        $weekStart = new \DateTimeImmutable('monday this week');
        $sessionsThisWeek = $this->ptSessions->countConfirmedOrCompletedForCoachBranchInRange(
            $coachProfile,
            $branch,
            $weekStart,
            $weekStart->modify('+7 days'),
        );

        return new JsonResponse([
            'branchId' => (string) $branch->getId(),
            'todaySessions' => array_map(fn (PtSession $s) => $this->serializeSession($s), $this->ptSessions->findForCoachBranchAndDate($coachProfile, $branch, $today)),
            'assignedMembersCount' => $this->ptSessions->countDistinctMembersForCoachAndBranch($coachProfile, $branch),
            'weeklyUtilization' => [
                'sessionsThisWeek' => $sessionsThisWeek,
                // Presentational only, see NOMINAL_WEEKLY_SESSION_CAPACITY's own docblock.
                'percentage' => min(100, (int) round($sessionsThisWeek / self::NOMINAL_WEEKLY_SESSION_CAPACITY * 100)),
            ],
            'unreadNotificationCount' => $this->notifications->countUnreadForUser($user),
        ]);
    }

    /**
     * functional requirements / architecture doc §5.2: Member stays
     * hub-scoped across branches — no `branch` param is even read here,
     * let alone honored, so a stray one can never accidentally narrow
     * this response (§6's explicit negative case).
     */
    #[Route('/member', name: 'dashboard_member', methods: ['GET'])]
    public function member(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null || !$this->isGranted(DashboardVoter::VIEW_MEMBER, $gym)) {
            return $this->forbidden();
        }

        $memberProfile = $this->memberProfiles->findOneByUser($user);
        if ($memberProfile === null) {
            return $this->notFound('No member profile found for this account.');
        }

        $now = new \DateTimeImmutable();
        $nextSession = null;
        foreach ($this->ptSessions->findForMember($memberProfile) as $session) {
            if ($session->getScheduledAt() >= $now && in_array($session->getStatus()->value, ['pending', 'confirmed'], true)) {
                $nextSession = $session;
                break;
            }
        }

        $recentAttendance = $this->attendanceLogs->findPaginatedForMember($memberProfile, 1, self::RECENT_ACTIVITY_LIMIT);

        return new JsonResponse([
            'nextSession' => $nextSession !== null ? $this->serializeSession($nextSession) : null,
            'recentAttendance' => array_map(fn ($log) => [
                'id' => (string) $log->getId(),
                'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
                'branch' => ['id' => (string) $log->getBranch()->getId(), 'name' => $log->getBranch()->getName()],
            ], $recentAttendance),
            'unreadNotificationCount' => $this->notifications->countUnreadForUser($user),
        ]);
    }

    private function serializeSession(PtSession $session): array
    {
        return [
            'id' => (string) $session->getId(),
            'memberName' => $session->getMember()->getUser()->getName(),
            'scheduledAt' => $session->getScheduledAt()->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $session->getDurationMinutes(),
            'status' => $session->getStatus()->value,
            'branch' => ['id' => (string) $session->getBranch()->getId(), 'name' => $session->getBranch()->getName()],
        ];
    }

    /** Owner: unchanged from ReportController's own `resolveReportBranch` — omitted means the gym-wide rollup. @return array{0: ?Branch, 1: ?JsonResponse} */
    private function resolveOwnerBranch(Gym $gym, Request $request): array
    {
        $branchId = $request->query->get('branch');
        if ($branchId === null || $branchId === '') {
            return [null, null];
        }

        $branch = $this->branches->find($branchId);
        if ($branch === null || $branch->getGym() !== $gym) {
            return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'branch does not belong to this gym.'], 400)];
        }

        return [$branch, null];
    }

    /**
     * Coach/Staff: `branch` is required if assigned to 2+ branches,
     * optional (defaults to the one) if assigned to exactly one, and
     * rejected with 403 if it names a branch they're not assigned to at
     * all — gym-management-dashboard-redesign.md §6's "New:" cases.
     *
     * @return array{0: ?Branch, 1: ?JsonResponse}
     */
    private function resolveScopedBranch(User $user, Request $request): array
    {
        $assignedBranches = array_map(fn ($a) => $a->getBranch(), $user->getBranchAssignments()->toArray());
        $branchId = $request->query->get('branch');

        if ($branchId === null || $branchId === '') {
            if (count($assignedBranches) === 1) {
                return [$assignedBranches[0], null];
            }
            if (count($assignedBranches) === 0) {
                return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'You are not assigned to any branch.'], 400)];
            }

            return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'branch is required — you are assigned to more than one.'], 400)];
        }

        $branch = $this->branches->find($branchId);
        $isAssigned = $branch !== null && in_array($branch, $assignedBranches, true);
        if (!$isAssigned) {
            return [null, new JsonResponse(['error' => 'forbidden', 'message' => 'You are not assigned to that branch.'], 403)];
        }

        return [$branch, null];
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
}

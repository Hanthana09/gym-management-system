<?php

namespace App\Controller;

use App\Entity\Branch;
use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\BranchRepository;
use App\Repository\DailyMetricSnapshotRepository;
use App\Repository\MembershipRepository;
use App\Reporting\AtRiskTrendBuilder;
use App\Reporting\PeakHoursAnalyzer;
use App\Security\Voter\ReportVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home-dashboard chart widgets for the Owner (a slice of roadmap Phase 11 —
 * Analytics & Reporting). These sit alongside, not on top of, the existing
 * `/api/reports/*` family (ReportController): same read model
 * (DAILY_METRIC_SNAPSHOT + live queries), same permission check
 * (ReportVoter::VIEW on the Owner's own Gym — no new auth scheme, no new
 * Voter), but chart-shaped DTOs instead of the report tables/exports.
 *
 * Hand-written routes under `/api/v1/analytics/*`, deliberately NOT API
 * Platform resource operations — mixing the two on one path is what
 * produced the historical `/api/api/v1/...` double-prefix bug. DailyMetric
 * Snapshot's own `#[ApiResource(operations: [])]` keeps it off the API
 * Platform router entirely, so there is no collision here.
 *
 * Scope (confirmed for this slice): Owner only. Every endpoint returns 403
 * for Coach / Staff / Member — none of these are branch-scoped for other
 * roles in this pass. `branch_id` (where accepted) is a query concern, not
 * a permission one: omitted means the gym-wide rollup, exactly like
 * `/api/reports/*` (functional requirements §14.5 / DESIGN-SYSTEM §4.2).
 *
 * `frozen` membership status and a persisted `returning` flag do not exist
 * in this system — the membership-health and new-vs-returning endpoints
 * map to concepts that do (see MembershipRepository::healthCountsByStatus
 * / ::newVsReturningByMonth). Coach-utilization is intentionally absent:
 * there is no persisted coach-availability / working-hours model to divide
 * booked sessions by, so a utilization percentage would be meaningless.
 */
#[Route('/api/v1/analytics')]
class AnalyticsController extends AbstractController
{
    private const DEFAULT_DAILY_WINDOW_DAYS = 30;
    private const DEFAULT_MONTHLY_WINDOW_MONTHS = 12;

    public function __construct(
        private readonly GymProvisioningService $gymProvisioning,
        private readonly DailyMetricSnapshotRepository $snapshots,
        private readonly MembershipRepository $memberships,
        private readonly BranchRepository $branches,
        private readonly PeakHoursAnalyzer $peakHours,
        private readonly AtRiskTrendBuilder $atRiskTrend,
    ) {
    }

    /**
     * Revenue trend from DAILY_METRIC_SNAPSHOT. `granularity=monthly` rolls
     * the daily rows up per calendar month (in PHP, matching
     * ReportController::dailyCheckinCounts and RevenueForecaster — this
     * codebase uses no native SQL); the frontend refetches on toggle rather
     * than pulling a year of daily rows to reslice client-side.
     */
    #[Route('/revenue', name: 'analytics_revenue', methods: ['GET'])]
    public function revenue(Request $request): JsonResponse
    {
        [$gym, $guard] = $this->resolveOwnerGym();
        if ($guard !== null) {
            return $guard;
        }

        [$branch, $branchError] = $this->resolveBranch($gym, $request);
        if ($branchError !== null) {
            return $branchError;
        }

        $monthly = $request->query->get('granularity') === 'monthly';
        [$from, $to] = $this->resolveRange($request, $monthly);
        $rows = $this->snapshots->findForDateRange($gym, $from, $to, $branch);

        if ($monthly) {
            $byMonth = [];
            foreach ($rows as $row) {
                $key = $row->getSnapshotDate()->format('Y-m');
                $byMonth[$key] = ($byMonth[$key] ?? 0.0) + (float) $row->getRevenue();
            }
            $series = [];
            foreach ($byMonth as $period => $total) {
                $series[] = ['period' => $period, 'revenue' => number_format($total, 2, '.', '')];
            }
        } else {
            $series = array_map(static fn (DailyMetricSnapshot $row) => [
                'period' => $row->getSnapshotDate()->format('Y-m-d'),
                'revenue' => number_format((float) $row->getRevenue(), 2, '.', ''),
            ], $rows);
        }

        return $this->ok($gym, $branch, [
            'granularity' => $monthly ? 'monthly' : 'daily',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'series' => $series,
        ]);
    }

    /** Live point-in-time membership buckets (no `frozen` — see class docblock). */
    #[Route('/membership-health', name: 'analytics_membership_health', methods: ['GET'])]
    public function membershipHealth(Request $request): JsonResponse
    {
        [$gym, $guard] = $this->resolveOwnerGym();
        if ($guard !== null) {
            return $guard;
        }

        [$branch, $branchError] = $this->resolveBranch($gym, $request);
        if ($branchError !== null) {
            return $branchError;
        }

        $asOf = new \DateTimeImmutable('today');

        return $this->ok($gym, $branch, [
            'asOf' => $asOf->format('Y-m-d'),
            'buckets' => $this->memberships->healthCountsByStatus($asOf, $branch),
        ]);
    }

    /** Day-of-week × hour-of-day check-in grid over a trailing window (live). */
    #[Route('/peak-hours', name: 'analytics_peak_hours', methods: ['GET'])]
    public function peakHours(Request $request): JsonResponse
    {
        [$gym, $guard] = $this->resolveOwnerGym();
        if ($guard !== null) {
            return $guard;
        }

        [$branch, $branchError] = $this->resolveBranch($gym, $request);
        if ($branchError !== null) {
            return $branchError;
        }

        $days = (int) $request->query->get('days', (string) self::DEFAULT_DAILY_WINDOW_DAYS);

        return $this->ok($gym, $branch, $this->peakHours->grid($days > 0 ? $days : self::DEFAULT_DAILY_WINDOW_DAYS, $branch));
    }

    /**
     * Per-branch revenue / active-members / attendance over a period. Hub-
     * wide by definition: a `branch_id` param is rejected (400) rather than
     * silently ignored, so a caller can't think it did something.
     */
    #[Route('/branch-comparison', name: 'analytics_branch_comparison', methods: ['GET'])]
    public function branchComparison(Request $request): JsonResponse
    {
        [$gym, $guard] = $this->resolveOwnerGym();
        if ($guard !== null) {
            return $guard;
        }

        if ($request->query->get('branch_id') !== null && $request->query->get('branch_id') !== '') {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'branch-comparison is hub-wide; drop the branch_id parameter.',
            ], 400);
        }

        $days = match ($request->query->get('period', '30d')) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };
        $to = (new \DateTimeImmutable('today'))->modify('-1 day');
        $from = $to->modify(sprintf('-%d days', $days - 1));

        /** @var array<string, array{branchId: string, branchName: string, revenue: float, attendanceCount: int, activeMembers: int, latest: string}> $byBranch */
        $byBranch = [];
        foreach ($this->snapshots->findPerBranchForDateRange($gym, $from, $to) as $row) {
            $branch = $row->getBranch();
            if ($branch === null) {
                continue;
            }
            $id = (string) $branch->getId();
            $date = $row->getSnapshotDate()->format('Y-m-d');
            if (!isset($byBranch[$id])) {
                $byBranch[$id] = [
                    'branchId' => $id,
                    'branchName' => $branch->getName(),
                    'revenue' => 0.0,
                    'attendanceCount' => 0,
                    'activeMembers' => 0,
                    'latest' => '',
                ];
            }
            $byBranch[$id]['revenue'] += (float) $row->getRevenue();
            $byBranch[$id]['attendanceCount'] += $row->getCheckinsCount();
            if ($date >= $byBranch[$id]['latest']) {
                $byBranch[$id]['latest'] = $date;
                $byBranch[$id]['activeMembers'] = $row->getActiveMembersCount();
            }
        }

        $branches = array_map(static fn (array $b) => [
            'branchId' => $b['branchId'],
            'branchName' => $b['branchName'],
            'revenue' => number_format($b['revenue'], 2, '.', ''),
            'attendanceCount' => $b['attendanceCount'],
            'activeMembers' => $b['activeMembers'],
        ], array_values($byBranch));

        usort($branches, static fn (array $a, array $b) => strcmp($a['branchName'], $b['branchName']));

        return new JsonResponse([
            'gymId' => (string) $gym->getId(),
            'period' => $days . 'd',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'branches' => $branches,
        ]);
    }

    /** Weekly at-risk-member counts for the trailing window (live sparkline). */
    #[Route('/at-risk-members', name: 'analytics_at_risk_members', methods: ['GET'])]
    public function atRiskMembers(Request $request): JsonResponse
    {
        [$gym, $guard] = $this->resolveOwnerGym();
        if ($guard !== null) {
            return $guard;
        }

        [$branch, $branchError] = $this->resolveBranch($gym, $request);
        if ($branchError !== null) {
            return $branchError;
        }

        $weeks = (int) $request->query->get('weeks', '12');
        $trend = $this->atRiskTrend->weeklyTrend($weeks > 0 ? $weeks : 12, $branch);

        return $this->ok($gym, $branch, [
            'weeks' => count($trend),
            'trend' => $trend,
            'current' => $trend === [] ? 0 : $trend[array_key_last($trend)]['count'],
        ]);
    }

    /**
     * New vs returning members per calendar month. "Returning" is derived,
     * not stored (see MembershipRepository::newVsReturningByMonth). Same
     * monthly-rollup window default as the revenue endpoint.
     */
    #[Route('/new-vs-returning', name: 'analytics_new_vs_returning', methods: ['GET'])]
    public function newVsReturning(Request $request): JsonResponse
    {
        [$gym, $guard] = $this->resolveOwnerGym();
        if ($guard !== null) {
            return $guard;
        }

        [$branch, $branchError] = $this->resolveBranch($gym, $request);
        if ($branchError !== null) {
            return $branchError;
        }

        [$from, $to] = $this->resolveRange($request, true);
        $byMonth = $this->memberships->newVsReturningByMonth($from, $to->modify('+1 day'), $branch);

        $series = [];
        foreach ($byMonth as $period => $counts) {
            $series[] = ['period' => $period, 'new' => $counts['new'], 'returning' => $counts['returning']];
        }

        return $this->ok($gym, $branch, [
            'granularity' => 'monthly',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'series' => $series,
        ]);
    }

    /**
     * @return array{0: ?Gym, 1: ?JsonResponse} [gym, null] on success; [null, error] otherwise.
     *
     * The explicit role check comes before ensureGymForOwner() on purpose:
     * that method provisions a gym for whoever is passed, so calling it for
     * a Coach/Staff/Member would create a stray gym as a side effect of a
     * request that must just 403.
     */
    private function resolveOwnerGym(): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return [null, new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401)];
        }
        if ($user->getRole() !== UserRole::OWNER) {
            return [null, $this->forbidden()];
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ReportVoter::VIEW, $gym)) {
            return [null, $this->forbidden()];
        }

        return [$gym, null];
    }

    /**
     * Mirrors ReportController::resolveReportBranch exactly — omitted/empty
     * `branch_id` is the gym-wide rollup (null); an explicit id that isn't
     * one of this gym's branches is a 400 (same response whether it belongs
     * to another gym or doesn't exist at all, so nothing leaks), never a
     * silent fallback to "all branches".
     *
     * @return array{0: ?Branch, 1: ?JsonResponse}
     */
    private function resolveBranch(Gym $gym, Request $request): array
    {
        $branchId = $request->query->get('branch_id');
        if ($branchId === null || $branchId === '') {
            return [null, null];
        }

        $branch = $this->branches->find($branchId);
        if ($branch === null || $branch->getGym() !== $gym) {
            return [null, new JsonResponse(['error' => 'invalid_request', 'message' => 'branch_id does not belong to this gym.'], 400)];
        }

        return [$branch, null];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} inclusive [from, to] date-only bounds */
    private function resolveRange(Request $request, bool $monthly): array
    {
        $today = new \DateTimeImmutable('today');
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $to = $toParam !== null && $toParam !== '' ? new \DateTimeImmutable($toParam) : $today;
        if ($fromParam !== null && $fromParam !== '') {
            $from = new \DateTimeImmutable($fromParam);
        } else {
            $from = $monthly
                ? $to->modify(sprintf('-%d months', self::DEFAULT_MONTHLY_WINDOW_MONTHS - 1))->modify('first day of this month')
                : $to->modify(sprintf('-%d days', self::DEFAULT_DAILY_WINDOW_DAYS - 1));
        }

        return [$from->setTime(0, 0), $to->setTime(0, 0)];
    }

    /** @param array<string, mixed> $payload */
    private function ok(Gym $gym, ?Branch $branch, array $payload): JsonResponse
    {
        return new JsonResponse([
            'gymId' => (string) $gym->getId(),
            'branchId' => $branch !== null ? (string) $branch->getId() : null,
            ...$payload,
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403);
    }
}

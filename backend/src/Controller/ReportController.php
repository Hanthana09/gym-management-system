<?php

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use App\Entity\User;
use App\Gym\GymProvisioningService;
use App\Repository\AttendanceLogRepository;
use App\Repository\BranchRepository;
use App\Repository\DailyMetricSnapshotRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MembershipRepository;
use App\Reporting\ReportExporter;
use App\Reporting\RetentionAnalyzer;
use App\Reporting\RevenueForecaster;
use App\Security\Voter\ReportVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §6.8 / §7's /reports/* endpoints, gated by ReportVoter
 * (§9.1, copied in for Phase 11). `/reports/attendance` existed since
 * Phase 5 (in AttendanceController, gated by AttendanceVoter::VIEW_ALL —
 * an ad hoc stand-in for the ReportVoter that didn't exist yet); it moves
 * here now that a real Reports module exists, extended with the daily
 * trend data functional requirements §10.2 needs, but its `count`/
 * `entries` fields are untouched — the frontend's live-counter hook
 * depends on that exact shape (see useLiveAttendanceCount.ts).
 *
 * roadmap Phase 16 / functional requirements §14.5: every endpoint below
 * accepts an optional `?branch_id` — omitted means the gym-wide rollup
 * (DESIGN-SYSTEM.md §4.2: "Owners on the reports screen default to the
 * gym-wide rollup, not a single branch," unlike front-desk check-in/plan
 * management, which default to the primary branch instead). No Voter
 * change: ReportVoter still only asks "does this Owner own this Gym" —
 * which branch's numbers come back is a query concern (architecture doc
 * §6.8's own explicit note), not a permission concern.
 */
#[Route('/api')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly GymProvisioningService $gymProvisioning,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly MembershipRepository $memberships,
        private readonly InvoiceRepository $invoices,
        private readonly DailyMetricSnapshotRepository $snapshots,
        private readonly RevenueForecaster $forecaster,
        private readonly RetentionAnalyzer $retention,
        private readonly ReportExporter $exporter,
        private readonly AuditLogger $auditLogger,
        private readonly BranchRepository $branches,
    ) {
    }

    /** functional requirements §10.1: today's check-ins, today's revenue, current active-member count. */
    #[Route('/reports/dashboard', name: 'reports_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ReportVoter::VIEW, $gym)) {
            return $this->forbidden();
        }

        [$branch, $error] = $this->resolveReportBranch($gym, $request);
        if ($error !== null) {
            return $error;
        }

        $today = new \DateTimeImmutable('today');

        return new JsonResponse([
            'gymId' => (string) $gym->getId(),
            'branchId' => $branch?->getId() !== null ? (string) $branch->getId() : null,
            // Same live mechanism as the Phase 5 attendance counter
            // (AttendanceLogRepository::countSince) — not a second pipeline.
            'todayCheckins' => $this->attendanceLogs->countSince($today, $branch),
            'todayRevenue' => $this->invoices->sumPaidAmountOnDate($today, $branch),
            'activeMembersCount' => $this->memberships->countWithinTermOnDate($today, $branch),
        ]);
    }

    /** functional requirements §4.2 (unchanged) + §10.2: adds a per-day trend alongside the existing live count/entries. */
    #[Route('/reports/attendance', name: 'reports_attendance', methods: ['GET'])]
    public function attendance(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ReportVoter::VIEW, $gym)) {
            return $this->forbidden();
        }

        [$branch, $error] = $this->resolveReportBranch($gym, $request);
        if ($error !== null) {
            return $error;
        }

        [$from, $toExclusive] = $this->parseDateRange($request);
        $entries = $this->attendanceLogs->findByDateRange($from, $toExclusive, $branch);

        return new JsonResponse([
            'gymId' => (string) $gym->getId(),
            'branchId' => $branch?->getId() !== null ? (string) $branch->getId() : null,
            'count' => $this->attendanceLogs->countSince(new \DateTimeImmutable('today'), $branch),
            'entries' => array_map(fn (AttendanceLog $log) => [
                'id' => (string) $log->getId(),
                'memberName' => $log->getMember()->getUser()->getName(),
                'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
                'method' => $log->getMethod()->value,
            ], $entries),
            'dailyCounts' => $this->dailyCheckinCounts($gym, $from, $toExclusive->modify('-1 day'), $branch),
        ]);
    }

    /** functional requirements §10.3. */
    #[Route('/reports/revenue-forecast', name: 'reports_revenue_forecast', methods: ['GET'])]
    public function revenueForecast(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ReportVoter::VIEW, $gym)) {
            return $this->forbidden();
        }

        [$branch, $error] = $this->resolveReportBranch($gym, $request);
        if ($error !== null) {
            return $error;
        }

        $horizonDays = $this->parseHorizonDays($request);
        $result = $this->forecastFor($gym, $horizonDays, $branch);

        return new JsonResponse([
            'hasEnoughData' => $result->hasEnoughData,
            'historical' => array_map(fn ($p) => ['date' => $p->date->format('Y-m-d'), 'revenue' => $p->revenue], $result->historical),
            'projected' => array_map(fn ($p) => ['date' => $p->date->format('Y-m-d'), 'revenue' => $p->revenue], $result->projected),
            'method' => $result->method,
        ]);
    }

    /** functional requirements §10.4: every entry carries a reason, never a bare score. */
    #[Route('/reports/retention', name: 'reports_retention', methods: ['GET'])]
    public function retention(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ReportVoter::VIEW, $gym)) {
            return $this->forbidden();
        }

        [$branch, $error] = $this->resolveReportBranch($gym, $request);
        if ($error !== null) {
            return $error;
        }

        $atRisk = $this->retention->atRiskMembers(new \DateTimeImmutable('today'), $branch);

        return new JsonResponse([
            'members' => array_map(fn (array $entry) => [
                'memberId' => (string) $entry['member']->getUser()->getId(),
                'memberName' => $entry['member']->getUser()->getName(),
                'reasons' => $entry['reasons'],
            ], $atRisk),
        ]);
    }

    /** functional requirements §10.5. EXPORT (not VIEW) so this gets its own audit trail. */
    #[Route('/reports/export', name: 'reports_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        if (!$this->isGranted(ReportVoter::EXPORT, $gym)) {
            return $this->forbidden();
        }

        $report = (string) $request->query->get('report', '');
        $format = (string) $request->query->get('format', '');
        if (!in_array($report, ['dashboard', 'attendance', 'revenue', 'retention'], true)
            || !in_array($format, ['csv', 'pdf'], true)
        ) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'report must be one of dashboard/attendance/revenue/retention, format must be csv or pdf.',
            ], 400);
        }

        [$branch, $error] = $this->resolveReportBranch($gym, $request);
        if ($error !== null) {
            return $error;
        }

        [$from, $toExclusive] = $this->parseDateRange($request);
        [$title, $headers, $rows] = $this->buildExportData($gym, $report, $from, $toExclusive, $branch);

        $body = $format === 'csv' ? $this->exporter->toCsv($headers, $rows) : $this->exporter->toPdf($title, $headers, $rows);
        $contentType = $format === 'csv' ? 'text/csv' : 'application/pdf';
        $filename = sprintf('%s-%s.%s', $report, (new \DateTimeImmutable())->format('Y-m-d'), $format);

        $this->auditLogger->log($user, 'report.exported', 'Gym', $gym->getId(), [
            'report' => $report,
            'format' => $format,
            'from' => $from->format('Y-m-d'),
            'to' => $toExclusive->modify('-1 day')->format('Y-m-d'),
            'branchId' => $branch?->getId() !== null ? (string) $branch->getId() : 'all',
        ]);

        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * roadmap Phase 16 / DESIGN-SYSTEM.md §4.2: omitted `branch_id` means
     * the gym-wide rollup (null) — reports default differently from
     * front-desk check-in/plan management, which default to the primary
     * branch instead. An explicit but invalid branch_id is a 400, never a
     * silent fallback to "all branches."
     *
     * @return array{0: ?Branch, 1: ?JsonResponse}
     */
    private function resolveReportBranch(Gym $gym, Request $request): array
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

    /** @return array{0: string, 1: string[], 2: array<int, array<int, string>>} [title, headers, rows] */
    private function buildExportData(Gym $gym, string $report, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch): array
    {
        return match ($report) {
            'attendance' => [
                'Attendance report',
                ['Date', 'Check-ins'],
                array_map(fn (array $d) => [$d['date'], (string) $d['count']], $this->dailyCheckinCounts($gym, $from, $toExclusive->modify('-1 day'), $branch)),
            ],
            'revenue' => [
                'Revenue forecast',
                ['Date', 'Revenue', 'Type'],
                $this->revenueExportRows($gym, $branch),
            ],
            'retention' => [
                'At-risk members',
                ['Member', 'Reasons'],
                array_map(
                    fn (array $entry) => [$entry['member']->getUser()->getName(), implode('; ', $entry['reasons'])],
                    $this->retention->atRiskMembers(new \DateTimeImmutable('today'), $branch),
                ),
            ],
            default => [
                'Dashboard summary',
                ['Metric', 'Value'],
                [
                    ["Today's check-ins", (string) $this->attendanceLogs->countSince(new \DateTimeImmutable('today'), $branch)],
                    ["Today's revenue", $this->invoices->sumPaidAmountOnDate(new \DateTimeImmutable('today'), $branch)],
                    ['Active members', (string) $this->memberships->countWithinTermOnDate(new \DateTimeImmutable('today'), $branch)],
                ],
            ],
        };
    }

    /** @return array<int, array<int, string>> */
    private function revenueExportRows(Gym $gym, ?Branch $branch): array
    {
        $result = $this->forecastFor($gym, 30, $branch);
        $rows = [];
        foreach ($result->historical as $point) {
            $rows[] = [$point->date->format('Y-m-d'), $point->revenue, 'actual'];
        }
        foreach ($result->projected as $point) {
            $rows[] = [$point->date->format('Y-m-d'), $point->revenue, 'projected'];
        }

        return $rows;
    }

    private function forecastFor(Gym $gym, int $horizonDays, ?Branch $branch = null): \App\Reporting\RevenueForecastResult
    {
        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');
        $historyStart = $yesterday->modify('-60 days');
        $snapshots = $this->snapshots->findForDateRange($gym, $historyStart, $yesterday, $branch);

        return $this->forecaster->forecast($snapshots, $horizonDays, new \DateTimeImmutable('today'));
    }

    /** @return array<int, array{date: string, count: int}> */
    private function dailyCheckinCounts(Gym $gym, \DateTimeImmutable $from, \DateTimeImmutable $to, ?Branch $branch = null): array
    {
        $today = new \DateTimeImmutable('today');
        $snapshotsByDate = [];
        foreach ($this->snapshots->findForDateRange($gym, $from, $to, $branch) as $snapshot) {
            $snapshotsByDate[$snapshot->getSnapshotDate()->format('Y-m-d')] = $snapshot;
        }

        $counts = [];
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            if ($date == $today) {
                // No snapshot exists for today until tonight's job runs — same live mechanism as the dashboard.
                $count = $this->attendanceLogs->countForDate($today, $branch);
            } else {
                $count = isset($snapshotsByDate[$key]) ? $snapshotsByDate[$key]->getCheckinsCount() : 0;
            }
            $counts[] = ['date' => $key, 'count' => $count];
        }

        return $counts;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, toExclusive] */
    private function parseDateRange(Request $request): array
    {
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');
        $from = $fromParam !== null ? new \DateTimeImmutable($fromParam) : new \DateTimeImmutable('today');
        $toExclusive = ($toParam !== null ? new \DateTimeImmutable($toParam) : new \DateTimeImmutable('today'))->modify('+1 day');

        return [$from, $toExclusive];
    }

    private function parseHorizonDays(Request $request): int
    {
        $days = (int) $request->query->get('days', '30');

        return in_array($days, [30, 60, 90], true) ? $days : 30;
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403);
    }
}

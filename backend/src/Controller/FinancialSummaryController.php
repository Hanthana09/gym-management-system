<?php

namespace App\Controller;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\PtSession;
use App\Entity\User;
use App\Gym\GymProvisioningService;
use App\Repository\BranchRepository;
use App\Repository\ExpenseRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ProductSaleRepository;
use App\Repository\PtSessionRepository;
use App\Security\Voter\ReportVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §6.13/§7's `GET /financial-summary` (Phase 17). A
 * hand-written controller, not a plain `#[ApiResource]` — the response
 * shape aggregates across `Invoice` (read-only, never modified — this
 * module is purely additive per §6.13), `PtSession`, `ProductSale`, and
 * `Expense`, and doesn't map onto a single entity, same rationale as
 * ReportController/AuthController being hand-written elsewhere in this
 * codebase. Gated by the existing `ReportVoter::VIEW` (no new Voter — the
 * doc's own note: "Owner, own gym only, optional branch filter" is the
 * exact same shape `ReportVoter` already covers).
 *
 * `resolveReportBranch()`/`parseDateRange()` below are copied from
 * ReportController, unchanged in behavior — same "omitted branch_id =
 * gym-wide rollup" convention, same "invalid branch_id is a 400, never a
 * silent fallback" rule.
 *
 * PT revenue is a read-time derived estimate, never persisted — architecture
 * doc §6.13: Σ (coach.hourly_rate × session.duration_minutes / 60) over
 * PT_SESSION rows with status confirmed/completed in range. Money math
 * throughout uses plain float + number_format (not bcmath — the
 * extension isn't enabled in this environment), matching
 * RevenueForecaster's established convention.
 */
#[Route('/api')]
class FinancialSummaryController extends AbstractController
{
    public function __construct(
        private readonly GymProvisioningService $gymProvisioning,
        private readonly BranchRepository $branches,
        private readonly InvoiceRepository $invoices,
        private readonly PtSessionRepository $ptSessions,
        private readonly ProductSaleRepository $productSales,
        private readonly ExpenseRepository $expenses,
    ) {
    }

    #[Route('/financial-summary', name: 'financial_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
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

        $membershipRevenue = (float) $this->invoices->sumPaidAmountForDateRange($from, $toExclusive, $branch);
        $ptRevenue = $this->ptRevenueForRange($from, $toExclusive, $branch);
        $retailRevenue = (float) $this->productSales->sumTotalAmountForDateRange($from, $toExclusive, $branch);
        $totalExpenses = (float) $this->expenses->sumAmountForDateRange($from, $toExclusive, $branch);
        $net = $membershipRevenue + $ptRevenue + $retailRevenue - $totalExpenses;

        return new JsonResponse([
            'gymId' => (string) $gym->getId(),
            'branchId' => $branch?->getId() !== null ? (string) $branch->getId() : null,
            'from' => $from->format('Y-m-d'),
            'to' => $toExclusive->modify('-1 day')->format('Y-m-d'),
            'membershipRevenue' => number_format($membershipRevenue, 2, '.', ''),
            'ptRevenue' => number_format($ptRevenue, 2, '.', ''),
            'retailRevenue' => number_format($retailRevenue, 2, '.', ''),
            'totalExpenses' => number_format($totalExpenses, 2, '.', ''),
            'net' => number_format($net, 2, '.', ''),
        ]);
    }

    /** architecture doc §6.13: Σ (coach.hourly_rate × session.duration_minutes / 60), confirmed|completed sessions only. */
    private function ptRevenueForRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?Branch $branch): float
    {
        $total = 0.0;
        foreach ($this->ptSessions->findConfirmedOrCompletedInRange($from, $toExclusive, $branch) as $session) {
            /* @var PtSession $session */
            $hourlyRate = (float) ($session->getCoach()->getHourlyRate() ?? '0');
            $total += $hourlyRate * $session->getDurationMinutes() / 60;
        }

        return $total;
    }

    /**
     * Copied from ReportController::resolveReportBranch() — same
     * "omitted branch_id = gym-wide rollup, invalid branch_id = 400"
     * behavior, kept identical so financial-summary branch scoping never
     * drifts from the rest of the analytics endpoints.
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

    /** Copied from ReportController::parseDateRange() — identical behavior. @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, toExclusive] */
    private function parseDateRange(Request $request): array
    {
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');
        $from = $fromParam !== null ? new \DateTimeImmutable($fromParam) : new \DateTimeImmutable('today');
        $toExclusive = ($toParam !== null ? new \DateTimeImmutable($toParam) : new \DateTimeImmutable('today'))->modify('+1 day');

        return [$from, $toExclusive];
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

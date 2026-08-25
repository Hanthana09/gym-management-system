<?php

namespace App\Controller;

use App\Billing\BillingService;
use App\Billing\InvoiceConflictException;
use App\Billing\PaymentAmountMismatchException;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\UserRole;
use App\Repository\BranchRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\InvoicePaymentVoter;
use App\Security\Voter\InvoiceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7's /invoices endpoints + §9.1's InvoiceVoter.
 * Payment method accepted from the Owner's mark-paid request is
 * deliberately restricted to cash/bank_transfer — see PaymentMethod's
 * docblock for why `gateway`/`referral_credit` are never client-supplied.
 */
#[Route('/api')]
class InvoiceController extends AbstractController
{
    private const OWNER_SELECTABLE_METHODS = [PaymentMethod::CASH, PaymentMethod::BANK_TRANSFER];

    /** gym-management-billing-v1.md §3.3 — the recurring payment endpoint's selectable methods (cash/card/bank_transfer). */
    private const RECURRING_PAYMENT_METHODS = [PaymentMethod::CASH, PaymentMethod::CARD, PaymentMethod::BANK_TRANSFER];

    public function __construct(
        private readonly BillingService $billing,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly BranchRepository $branchRepository,
    ) {
    }

    #[Route('/invoices', name: 'invoices_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        // Plain role gate, not InvoiceVoter::VIEW — same reasoning as
        // MemberController::list(): VIEW is an object-level check ("can I
        // see THIS invoice") and this endpoint returns the whole list with
        // no single subject to check against. InvoiceVoter is used
        // per-object below (mark-paid) and in the Member's own list.
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $invoices = array_map(fn (Invoice $invoice) => $this->serialize($invoice, includeMember: true), $this->billing->listAllForOwner());

        return new JsonResponse(['invoices' => $invoices]);
    }

    #[Route('/members/me/invoices', name: 'members_me_invoices', methods: ['GET'])]
    public function myInvoices(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        $invoices = array_map(fn (Invoice $invoice) => $this->serialize($invoice, includeMember: false), $this->billing->listForMember($member));

        return new JsonResponse(['invoices' => $invoices]);
    }

    #[Route('/invoices/{id}/mark-paid', name: 'invoices_mark_paid', methods: ['PATCH'])]
    public function markPaid(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $invoice = $this->invoiceRepository->find($id);
        if ($invoice === null) {
            return $this->notFound('Invoice not found.');
        }

        if (!$this->isGranted(InvoiceVoter::MARK_PAID, $invoice)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $methodValue = (string) ($data['paymentMethod'] ?? '');
        $method = PaymentMethod::tryFrom($methodValue);
        if ($method === null || !in_array($method, self::OWNER_SELECTABLE_METHODS, true)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'paymentMethod must be one of: ' . implode(', ', array_map(fn (PaymentMethod $m) => $m->value, self::OWNER_SELECTABLE_METHODS)),
            ], 400);
        }

        try {
            $this->billing->markPaid($invoice, $user, $method);
        } catch (InvoiceConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serialize($invoice, includeMember: true));
    }

    /** gym-management-billing-v1.md §5.2 — the recurring-billing payment endpoint. */
    #[Route('/invoices/{id}/payments', name: 'invoices_record_payment', methods: ['POST'])]
    public function recordPayment(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $invoice = $this->invoiceRepository->find($id);
        if ($invoice === null) {
            return $this->notFound('Invoice not found.');
        }

        if (!$this->isGranted(InvoicePaymentVoter::RECORD_PAYMENT, $invoice)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);

        $resetBillingCycle = (bool) ($data['resetBillingCycle'] ?? false);
        // §5.3: fail the whole request, never silently coerce to false.
        if ($resetBillingCycle && !$this->isGranted(InvoicePaymentVoter::RESET_BILLING_CYCLE, $invoice)) {
            return $this->forbidden();
        }

        if (!isset($data['amount']) || !is_numeric((string) $data['amount'])) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'amount is required and must be numeric.'], 400);
        }
        $amount = number_format((float) $data['amount'], 2, '.', '');

        $method = PaymentMethod::tryFrom((string) ($data['method'] ?? ''));
        if ($method === null || !in_array($method, self::RECURRING_PAYMENT_METHODS, true)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'method must be one of: ' . implode(', ', array_map(fn (PaymentMethod $m) => $m->value, self::RECURRING_PAYMENT_METHODS)),
            ], 400);
        }

        $note = isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null;

        try {
            $payment = $this->billing->recordRecurringPayment($invoice, $user, $amount, $method, $resetBillingCycle, $note);
        } catch (InvoiceConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        } catch (PaymentAmountMismatchException $exception) {
            return new JsonResponse(['error' => 'amount_mismatch', 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse($this->serializePayment($payment, $invoice), 201);
    }

    /** gym-management-billing-v1.md §6 — the dashboard "needs attention" widget's data source. */
    #[Route('/branches/{id}/invoices', name: 'branches_invoices_needing_attention', methods: ['GET'])]
    public function branchInvoicesNeedingAttention(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if (!in_array($user->getRole(), [UserRole::OWNER, UserRole::STAFF], true)) {
            return $this->forbidden();
        }

        $branch = $this->branchRepository->find($id);
        if ($branch === null) {
            return $this->notFound('Branch not found.');
        }

        if ($user->getRole() === UserRole::STAFF && !$user->getBranchAssignments()->exists(
            fn ($key, $assignment) => $assignment->getBranch() === $branch,
        )) {
            return $this->forbidden();
        }

        $invoices = array_map(
            fn (Invoice $invoice) => $this->serializeAttentionRow($invoice),
            $this->invoiceRepository->findNeedingAttentionForBranch($branch, new \DateTimeImmutable('today')),
        );

        return new JsonResponse(['invoices' => $invoices]);
    }

    private function serializePayment(Payment $payment, Invoice $invoice): array
    {
        return [
            'id' => (string) $payment->getId(),
            'invoiceId' => (string) $invoice->getId(),
            'amount' => $payment->getAmount(),
            'method' => $payment->getMethod()->value,
            'resetBillingCycle' => $payment->isResetBillingCycle(),
            'note' => $payment->getNote(),
            'paidAt' => $payment->getPaidAt()->format(\DateTimeInterface::ATOM),
            'invoice' => $this->serialize($invoice, includeMember: true),
        ];
    }

    private function serializeAttentionRow(Invoice $invoice): array
    {
        $membership = $invoice->getMembership();
        $memberUser = $membership->getMember()->getUser();

        return [
            'id' => (string) $invoice->getId(),
            'status' => $invoice->getStatus()->value,
            'amount' => $invoice->getAmount(),
            'periodStart' => $invoice->getPeriodStart()?->format('Y-m-d'),
            'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
            'member' => [
                'id' => (string) $memberUser->getId(),
                'name' => $memberUser->getName(),
            ],
        ];
    }

    private function serialize(Invoice $invoice, bool $includeMember): array
    {
        $membership = $invoice->getMembership();
        $recordedBy = $invoice->getRecordedBy();

        $data = [
            'id' => (string) $invoice->getId(),
            'amount' => $invoice->getAmount(),
            'status' => $invoice->getStatus()->value,
            'paymentMethod' => $invoice->getPaymentMethod()?->value,
            'recordedByName' => $recordedBy?->getName(),
            'issuedAt' => $invoice->getIssuedAt()->format(\DateTimeInterface::ATOM),
            'paidAt' => $invoice->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'plan' => [
                'name' => $membership->getPlan()->getName(),
            ],
        ];

        if ($includeMember) {
            $memberUser = $membership->getMember()->getUser();
            $data['member'] = [
                'id' => (string) $memberUser->getId(),
                'name' => $memberUser->getName(),
            ];
        }

        return $data;
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

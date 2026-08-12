<?php

namespace App\Controller;

use App\Entity\ReferralCode;
use App\Entity\ReferralLead;
use App\Entity\User;
use App\Enum\UserRole;
use App\Referral\ReferralService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * roadmap Phase 9.2 (GTM Pillar B / Pillar F). No dedicated Voter here —
 * these resources aren't in architecture doc §2's permission table at
 * all (this is new-to-Phase-9 scope), and the checks needed are simple
 * role gates, not the row-level "own gym/own client" logic the Voter
 * abstraction exists for elsewhere in this codebase.
 */
class ReferralController extends AbstractController
{
    public function __construct(private readonly ReferralService $referrals)
    {
    }

    /** "Any authenticated Coach or Owner can submit a lead" — deliberately lightweight capture, not gym provisioning. */
    #[Route('/referrals', name: 'referrals_create', methods: ['POST'])]
    public function createLead(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        if ($user->getRole() !== UserRole::COACH && $user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $prospectGymName = trim((string) ($data['prospectGymName'] ?? ''));
        $contactName = $this->nullableTrim($data['contactName'] ?? null);
        $contactEmail = $this->nullableTrim($data['contactEmail'] ?? null);
        $contactPhone = $this->nullableTrim($data['contactPhone'] ?? null);

        if ($prospectGymName === '' || ($contactEmail === null && $contactPhone === null)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'prospectGymName and at least one of contactEmail/contactPhone are required.',
            ], 400);
        }

        $lead = $this->referrals->submitLead($user, $prospectGymName, $contactName, $contactEmail, $contactPhone);

        return new JsonResponse($this->serializeLead($lead), 201);
    }

    /** Own submitted leads — used by both the Coach's and the Owner's dashboards. */
    #[Route('/referrals/me', name: 'referrals_me', methods: ['GET'])]
    public function myLeads(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        return new JsonResponse([
            'leads' => array_map(fn (ReferralLead $lead) => $this->serializeLead($lead), $this->referrals->listLeadsForUser($user)),
        ]);
    }

    /** "One stable code per Owner" (GTM Pillar F) — lazily created on first request, same as Gym. */
    #[Route('/referral-code', name: 'referral_code_show', methods: ['GET'])]
    public function myCode(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        return new JsonResponse($this->serializeCode($this->referrals->getOrCreateCodeForOwner($user)));
    }

    /**
     * Stand-in for the real "a new gym signed up with this code" flow,
     * which doesn't exist yet (roadmap: "credit application can be a
     * manual/admin action for now"). Open to any authenticated user since
     * there's no "prospective new owner" identity modeled in this
     * single-gym product to scope it to.
     */
    #[Route('/referral-code/redeem', name: 'referral_code_redeem', methods: ['POST'])]
    public function redeemCode(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'code is required.'], 400);
        }

        $referralCode = $this->referrals->redeemCode($code);
        if ($referralCode === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'No referral code matches that value.'], 404);
        }

        return new JsonResponse($this->serializeCode($referralCode));
    }

    private function serializeLead(ReferralLead $lead): array
    {
        return [
            'id' => (string) $lead->getId(),
            'prospectGymName' => $lead->getProspectGymName(),
            'contactName' => $lead->getContactName(),
            'contactEmail' => $lead->getContactEmail(),
            'contactPhone' => $lead->getContactPhone(),
            'status' => $lead->getStatus()->value,
            'createdAt' => $lead->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeCode(ReferralCode $code): array
    {
        return [
            'code' => $code->getCode(),
            'usageCount' => $code->getUsageCount(),
            'creditsAvailable' => $code->getCreditsAvailable(),
            'createdAt' => $code->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403);
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

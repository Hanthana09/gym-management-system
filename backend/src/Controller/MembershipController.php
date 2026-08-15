<?php

namespace App\Controller;

use App\Branch\BranchResolver;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Gym\GymProvisioningService;
use App\Membership\MembershipConflictException;
use App\Membership\MembershipPlanHasOngoingMembershipsException;
use App\Membership\MembershipService;
use App\Repository\MemberProfileRepository;
use App\Repository\MembershipPlanRepository;
use App\Repository\MembershipRepository;
use App\Security\Voter\MembershipVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class MembershipController extends AbstractController
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly MembershipPlanRepository $planRepository,
        private readonly MembershipRepository $membershipRepository,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly BranchResolver $branches,
    ) {
    }

    // ---- Plan CRUD (Owner) -------------------------------------------------

    /** roadmap Phase 16: plans are branch-scoped — an optional `branchId` picks which branch this plan belongs to, defaulting to the gym's primary branch (so a single-branch gym's callers need no change at all). */
    #[Route('/membership-plans', name: 'membership_plans_create', methods: ['POST'])]
    public function createPlan(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        [$name, $price, $durationDays, $features, $error] = $this->parsePlanInput($data, requireAll: true);
        if ($error !== null) {
            return $error;
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $branch = $this->branches->resolve($gym, isset($data['branchId']) ? (string) $data['branchId'] : null);
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not belong to this gym.'], 400);
        }

        $candidate = new MembershipPlan($branch, $name, $price, $durationDays, $features);
        if (!$this->isGranted(MembershipVoter::MANAGE, $candidate)) {
            return $this->forbidden();
        }

        $plan = $this->memberships->createPlan($branch, $name, $price, $durationDays, $features);

        return new JsonResponse($this->serializePlan($plan), 201);
    }

    /** `?branchId=` picks a specific branch's plans; omitted defaults to the primary branch — same "which branch am I editing" scoping as createPlan(). */
    #[Route('/membership-plans', name: 'membership_plans_list', methods: ['GET'])]
    public function listPlans(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $branch = $this->branches->resolve($gym, $request->query->get('branchId'));
        if ($branch === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not belong to this gym.'], 400);
        }

        $plans = array_map(fn (MembershipPlan $plan) => $this->serializePlan($plan), $this->memberships->listPlansForBranch($branch));

        return new JsonResponse(['plans' => $plans, 'branchId' => (string) $branch->getId()]);
    }

    #[Route('/membership-plans/{id}', name: 'membership_plans_update', methods: ['PATCH'])]
    public function updatePlan(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $plan = $this->planRepository->find($id);
        if ($plan === null) {
            return $this->notFound('Plan not found.');
        }
        if (!$this->isGranted(MembershipVoter::MANAGE, $plan)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        [$name, $price, $durationDays, $features, $error] = $this->parsePlanInput($data, requireAll: false);
        if ($error !== null) {
            return $error;
        }

        $this->memberships->updatePlan($plan, $name, $price, $durationDays, $features);

        return new JsonResponse($this->serializePlan($plan));
    }

    #[Route('/membership-plans/{id}', name: 'membership_plans_delete', methods: ['DELETE'])]
    public function deletePlan(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $plan = $this->planRepository->find($id);
        if ($plan === null) {
            return $this->notFound('Plan not found.');
        }
        if (!$this->isGranted(MembershipVoter::MANAGE, $plan)) {
            return $this->forbidden();
        }

        try {
            $this->memberships->deletePlan($plan);
        } catch (MembershipPlanHasOngoingMembershipsException $exception) {
            return new JsonResponse(['error' => 'plan_has_ongoing_memberships', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(null, 204);
    }

    // ---- Enrollment (Owner) -------------------------------------------------

    #[Route('/memberships', name: 'memberships_create', methods: ['POST'])]
    public function enroll(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $memberUserId = (string) ($data['memberUserId'] ?? '');
        $planId = (string) ($data['planId'] ?? '');
        $autoRenew = (bool) ($data['autoRenew'] ?? false);

        if ($memberUserId === '' || $planId === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'memberUserId and planId are required.'], 400);
        }

        $plan = $this->planRepository->find($planId);
        if ($plan === null) {
            return $this->notFound('Plan not found.');
        }
        if (!$this->isGranted(MembershipVoter::MANAGE, $plan)) {
            return $this->forbidden();
        }

        $member = $this->memberProfiles->find($memberUserId);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        try {
            $membership = $this->memberships->enroll($member, $plan, $autoRenew);
        } catch (MembershipConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serializeMembership($membership), 201);
    }

    // ---- Member's own membership (self-service) -----------------------------

    #[Route('/members/me/membership', name: 'members_me_membership', methods: ['GET'])]
    public function myMembership(): JsonResponse
    {
        $member = $this->currentMemberProfile();
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $membership = $this->memberships->getMembershipForMember($member);
        if ($membership !== null && !$this->isGranted(MembershipVoter::VIEW, $membership)) {
            return $this->forbidden();
        }

        return new JsonResponse(['membership' => $membership !== null ? $this->serializeMembership($membership) : null]);
    }

    #[Route('/members/me/membership/pause', name: 'members_me_membership_pause', methods: ['PATCH'])]
    public function pause(): JsonResponse
    {
        return $this->respond(fn (Membership $m) => $this->memberships->pause($m));
    }

    #[Route('/members/me/membership/resume', name: 'members_me_membership_resume', methods: ['PATCH'])]
    public function resume(): JsonResponse
    {
        return $this->respond(fn (Membership $m) => $this->memberships->resume($m));
    }

    #[Route('/members/me/membership/cancel', name: 'members_me_membership_cancel', methods: ['PATCH'])]
    public function cancel(): JsonResponse
    {
        return $this->respond(fn (Membership $m) => $this->memberships->cancel($m));
    }

    /** @param callable(Membership): void $action */
    private function respond(callable $action): JsonResponse
    {
        $member = $this->currentMemberProfile();
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $membership = $this->memberships->getMembershipForMember($member);
        if ($membership === null) {
            return $this->notFound('No membership found.');
        }
        if (!$this->isGranted(MembershipVoter::RESPOND, $membership)) {
            return $this->forbidden();
        }

        try {
            $action($membership);
        } catch (MembershipConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serializeMembership($membership));
    }

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

    // ---- helpers -------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?string[], 4: ?JsonResponse}
     */
    private function parsePlanInput(array $data, bool $requireAll): array
    {
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : null;
        $price = array_key_exists('price', $data) ? (string) $data['price'] : null;
        $durationDays = array_key_exists('durationDays', $data) ? (int) $data['durationDays'] : null;
        $features = array_key_exists('features', $data) && is_array($data['features'])
            ? array_values(array_map('strval', $data['features']))
            : null;

        if ($requireAll && ($name === null || $name === '' || $price === null || $durationDays === null)) {
            return [null, null, null, null, new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'name, price, and durationDays are required.',
            ], 400)];
        }

        return [$name, $price, $durationDays, $features ?? ($requireAll ? [] : null), null];
    }

    private function serializePlan(MembershipPlan $plan): array
    {
        return [
            'id' => (string) $plan->getId(),
            'branchId' => (string) $plan->getBranch()->getId(),
            'name' => $plan->getName(),
            'price' => $plan->getPrice(),
            'durationDays' => $plan->getDurationDays(),
            'features' => $plan->getFeatures(),
        ];
    }

    private function serializeMembership(Membership $membership): array
    {
        return [
            'id' => (string) $membership->getId(),
            'plan' => $this->serializePlan($membership->getPlan()),
            'startDate' => $membership->getStartDate()->format('Y-m-d'),
            'endDate' => $membership->getEndDate()->format('Y-m-d'),
            'status' => $membership->getStatus()->value,
            'autoRenew' => $membership->isAutoRenew(),
            'daysUntilExpiry' => $membership->daysUntilExpiry(),
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

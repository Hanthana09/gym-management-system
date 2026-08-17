<?php

namespace App\Controller;

use App\Branch\BranchAssignmentConflictException;
use App\Branch\BranchDeletionConflictException;
use App\Branch\BranchService;
use App\Entity\Branch;
use App\Entity\User;
use App\Enum\BranchStatus;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\BranchAssignmentRepository;
use App\Repository\BranchRepository;
use App\Repository\GymRepository;
use App\Repository\UserRepository;
use App\Security\Voter\BranchVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7 / §6.12: branch CRUD and Coach/Staff assignment,
 * all gated by BranchVoter::MANAGE (Owner only) except the plain list,
 * which per §7 is "any authenticated user in the gym — read-only, needed
 * for branch pickers in forms."
 */
#[Route('/api')]
class BranchController extends AbstractController
{
    public function __construct(
        private readonly BranchRepository $branches,
        private readonly BranchAssignmentRepository $assignments,
        private readonly UserRepository $users,
        private readonly BranchService $branchService,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
    ) {
    }

    #[Route('/branches', name: 'branches_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($this->resolveGymOwnerContext($user));

        return new JsonResponse(['branches' => array_map(fn (Branch $b) => $this->serialize($b), $this->branches->findByGym($gym))]);
    }

    #[Route('/branches', name: 'branches_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $name = trim((string) ($data['name'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;

        if ($name === '' || $address === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'name and address are required.'], 400);
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $candidate = new Branch($gym, $name, $address, $phone === '' ? null : $phone);
        if (!$this->isGranted(BranchVoter::MANAGE, $candidate)) {
            return $this->forbidden();
        }

        $branch = $this->branchService->create($gym, $name, $address, $phone === '' ? null : $phone);

        return new JsonResponse($this->serialize($branch), 201);
    }

    #[Route('/branches/{id}', name: 'branches_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $branch = $this->branches->find($id);
        if ($branch === null) {
            return $this->notFound('Branch not found.');
        }
        if (!$this->isGranted(BranchVoter::MANAGE, $branch)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : null;
        $address = array_key_exists('address', $data) ? trim((string) $data['address']) : null;
        $phone = array_key_exists('phone', $data) ? trim((string) $data['phone']) : null;

        $this->branchService->update($branch, $name === '' ? null : $name, $address === '' ? null : $address, $phone);

        $status = array_key_exists('status', $data) ? BranchStatus::tryFrom((string) $data['status']) : null;
        if ($status === BranchStatus::INACTIVE) {
            $this->branchService->deactivate($branch);
        } elseif ($status === BranchStatus::ACTIVE) {
            $this->branchService->activate($branch);
        }

        return new JsonResponse($this->serialize($branch));
    }

    /**
     * Branch delete facility: a genuine hard delete, but only ever for a
     * branch BranchService confirms has never actually been used (no
     * attendance/plans/PT sessions) and isn't the primary branch — see
     * that method's own docblock for why. Anything else stays a 409
     * pointing back at Deactivate, which already exists for this.
     */
    #[Route('/branches/{id}', name: 'branches_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $branch = $this->branches->find($id);
        if ($branch === null) {
            return $this->notFound('Branch not found.');
        }
        if (!$this->isGranted(BranchVoter::MANAGE, $branch)) {
            return $this->forbidden();
        }

        try {
            $this->branchService->delete($branch);
        } catch (BranchDeletionConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(null, 204);
    }

    /** Owner-only source for the assignment picker: every active Coach/Staff account (roadmap Phase 16, not in §7's representative list but required for the assignment UI to be usable). */
    #[Route('/branches/assignable-users', name: 'branches_assignable_users', methods: ['GET'])]
    public function assignableUsers(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $users = array_map(fn (User $u) => [
            'id' => (string) $u->getId(),
            'name' => $u->getName(),
            'role' => $u->getRole()->value,
        ], $this->users->findActiveByRoles([UserRole::COACH, UserRole::STAFF]));

        return new JsonResponse(['users' => $users]);
    }

    #[Route('/branches/{id}/assign', name: 'branches_assign', methods: ['POST'])]
    public function assign(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $branch = $this->branches->find($id);
        if ($branch === null) {
            return $this->notFound('Branch not found.');
        }
        if (!$this->isGranted(BranchVoter::MANAGE, $branch)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $userId = (string) ($data['userId'] ?? '');
        if ($userId === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'userId is required.'], 400);
        }

        $target = $this->users->find($userId);
        if ($target === null) {
            return $this->notFound('User not found.');
        }

        try {
            $this->branchService->assign($branch, $target);
        } catch (BranchAssignmentConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serialize($branch), 201);
    }

    #[Route('/branches/{id}/assign/{userId}', name: 'branches_unassign', methods: ['DELETE'])]
    public function unassign(string $id, string $userId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $branch = $this->branches->find($id);
        if ($branch === null) {
            return $this->notFound('Branch not found.');
        }
        if (!$this->isGranted(BranchVoter::MANAGE, $branch)) {
            return $this->forbidden();
        }

        $target = $this->users->find($userId);
        if ($target === null) {
            return $this->notFound('User not found.');
        }

        try {
            $this->branchService->unassign($branch, $target);
        } catch (BranchAssignmentConflictException $exception) {
            return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->serialize($branch));
    }

    private function serialize(Branch $branch): array
    {
        return [
            'id' => (string) $branch->getId(),
            'name' => $branch->getName(),
            'address' => $branch->getAddress(),
            'phone' => $branch->getPhone(),
            'isPrimary' => $branch->isPrimary(),
            'status' => $branch->getStatus()->value,
            'assignments' => array_map(fn ($a) => [
                'userId' => (string) $a->getUser()->getId(),
                'name' => $a->getUser()->getName(),
                'role' => $a->getUser()->getRole()->value,
            ], $this->assignments->findByBranch($branch)),
        ];
    }

    /**
     * Single-gym product (CLAUDE.md): a non-Owner (Coach/Staff/Member)
     * calling GET /branches still needs "the" gym resolved to list its
     * branches, the same read-only fallback NotificationController's
     * announcement-gym-resolution already uses — this is just object
     * lookup for the query, not a security decision (BranchVoter is never
     * consulted for a plain list).
     */
    private function resolveGymOwnerContext(User $user): User
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $user;
        }

        // Single-gym product — GymProvisioningService::ensureGymForOwner()
        // needs an Owner to lazily-create against, but for a non-Owner
        // caller here there's always already exactly one gym (they were
        // necessarily invited by its Owner), so this never actually
        // provisions anything new — same fallback NotificationController's
        // announcement-gym-resolution already uses.
        $gym = $this->gyms->findTheOnlyGym();

        return $gym?->getOwner() ?? throw $this->createNotFoundException('No gym found.');
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

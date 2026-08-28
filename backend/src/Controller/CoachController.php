<?php

namespace App\Controller;

use App\Coach\CoachService;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Gym\GymProvisioningService;
use App\Repository\CoachProfileRepository;
use App\Repository\GymRepository;
use App\Repository\UserRepository;
use App\Security\Voter\CoachManagementVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * gym-management-coach-management.md: coach CRUD for an Owner.
 *
 *   POST   /api/coaches          create an immediately-active coach (Owner)
 *   GET    /api/coaches/:id      coach detail (Owner / Staff / the coach themselves)
 *   PATCH  /api/coaches/:id      edit identity + profile fields (Owner — COACH_MANAGE)
 *   PATCH  /api/coaches/:id/status  suspend / reactivate (Owner — COACH_MANAGE)
 *
 * The `/coaches` *list* and `/coaches/:id/schedule` endpoints live in
 * PtSessionController (they predate this feature and return PtSession-
 * shaped data, not CoachProfile) — untouched here. No route collision:
 * `GET /coaches` (list) and `POST /coaches` (create) differ by method;
 * `/coaches/:id` and `/coaches/:id/schedule` differ by path.
 *
 * Direct creation (POST /coaches) deliberately overrides architecture
 * doc §6.7 / CLAUDE.md's "onboarding is always invite → approve for
 * coaches" — a product decision recorded in the spec doc and CLAUDE.md's
 * updated anti-pattern note. Same shape as MemberController::create()'s
 * walk-in path: a plain Owner role gate (no CoachProfile subject exists
 * yet for CoachManagementVoter to vote on), account ACTIVE on creation.
 */
#[Route('/api')]
class CoachController extends AbstractController
{
    public function __construct(
        private readonly CoachProfileRepository $coachProfiles,
        private readonly UserRepository $users,
        private readonly CoachService $coaches,
        private readonly GymRepository $gyms,
        private readonly GymProvisioningService $provisioning,
    ) {
    }

    #[Route('/coaches', name: 'coaches_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $gym = $this->gyms->findOneByOwner($user) ?? $this->provisioning->ensureGymForOwner($user);

        $data = $this->decode($request);

        $name = trim((string) ($data['name'] ?? ''));
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? trim((string) $data['email']) : null;
        $phone = isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null;
        if ($name === '' || ($email === null && $phone === null)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'name and at least one of email/phone are required.',
            ], 400);
        }

        if ($email !== null && $this->users->findOneByEmail($email) !== null) {
            return $this->conflict('That email address is already in use.');
        }
        if ($phone !== null && $this->users->findOneByPhone($phone) !== null) {
            return $this->conflict('That phone number is already in use.');
        }

        [$fields, $error] = $this->parseProfileFields($data);
        if ($error !== null) {
            return $error;
        }

        $profile = $this->coaches->createCoach($gym, $name, $email, $phone, $fields, $user);

        return new JsonResponse($this->serialize($profile), 201);
    }

    #[Route('/coaches/{id}', name: 'coaches_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $coach = $this->coachProfiles->find($id);
        if ($coach === null) {
            return $this->notFound('Coach not found.');
        }

        // Owner / Staff read the directory; a coach may read their own
        // record. Coach management writes stay Owner-only (COACH_MANAGE).
        $isSelf = $user->getRole() === UserRole::COACH && $coach->getUser() === $user;
        if (!in_array($user->getRole(), [UserRole::OWNER, UserRole::STAFF], true) && !$isSelf) {
            return $this->forbidden();
        }

        return new JsonResponse($this->serialize($coach));
    }

    #[Route('/coaches/{id}', name: 'coaches_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $coach = $this->coachProfiles->find($id);
        if ($coach === null) {
            return $this->notFound('Coach not found.');
        }

        if (!$this->isGranted(CoachManagementVoter::MANAGE, $coach)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $target = $coach->getUser();
        $fields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'name cannot be empty.'], 400);
            }
            $fields['name'] = $name;
        }

        // Resolve the email/phone the account would have *after* this
        // patch, so "you can't remove the last contact method" is checked
        // against the result, not the current state.
        $resultingEmail = $target->getEmail();
        $resultingPhone = $target->getPhone();

        if (array_key_exists('email', $data)) {
            $email = $data['email'] === null || trim((string) $data['email']) === '' ? null : trim((string) $data['email']);
            if ($email !== null) {
                $existing = $this->users->findOneByEmail($email);
                if ($existing !== null && $existing !== $target) {
                    return $this->conflict('That email address is already in use.');
                }
            }
            $fields['email'] = $email;
            $resultingEmail = $email;
        }

        if (array_key_exists('phone', $data)) {
            $phone = $data['phone'] === null || trim((string) $data['phone']) === '' ? null : trim((string) $data['phone']);
            if ($phone !== null) {
                $existing = $this->users->findOneByPhone($phone);
                if ($existing !== null && $existing !== $target) {
                    return $this->conflict('That phone number is already in use.');
                }
            }
            $fields['phone'] = $phone;
            $resultingPhone = $phone;
        }

        if ($resultingEmail === null && $resultingPhone === null) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'A coach must keep at least one of email/phone.',
            ], 400);
        }

        [$profileFields, $error] = $this->parseProfileFields($data);
        if ($error !== null) {
            return $error;
        }
        $fields = [...$fields, ...$profileFields];

        $this->coaches->updateProfile($coach, $fields, $user);

        return new JsonResponse($this->serialize($coach));
    }

    /**
     * architecture doc §7 shape mirrors PATCH /members/:id/status — only
     * active|suspended are ever client-selectable; there is no hard
     * delete for a User (PtSession history references the coach), so
     * "remove a coach" is a suspend, same as members.
     */
    #[Route('/coaches/{id}/status', name: 'coaches_update_status', methods: ['PATCH'])]
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $coach = $this->coachProfiles->find($id);
        if ($coach === null) {
            return $this->notFound('Coach not found.');
        }

        if (!$this->isGranted(CoachManagementVoter::MANAGE, $coach)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $newStatus = UserStatus::tryFrom((string) ($data['status'] ?? ''));
        if ($newStatus === null || !in_array($newStatus, [UserStatus::ACTIVE, UserStatus::SUSPENDED], true)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'status must be one of: active, suspended.',
            ], 400);
        }

        $this->coaches->updateStatus($coach, $newStatus, $user);

        return new JsonResponse($this->serialize($coach));
    }

    /**
     * @return array{0: array{specialty?: ?string, bio?: ?string, hourlyRate?: ?string}, 1: ?JsonResponse}
     */
    private function parseProfileFields(array $data): array
    {
        $fields = [];

        foreach (['specialty', 'bio'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key] === null ? null : trim((string) $data[$key]);
                $fields[$key] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('hourlyRate', $data)) {
            $raw = $data['hourlyRate'];
            if ($raw === null || $raw === '') {
                $fields['hourlyRate'] = null;
            } elseif (!is_numeric($raw) || (float) $raw < 0) {
                return [[], new JsonResponse([
                    'error' => 'invalid_request',
                    'message' => 'hourlyRate must be a non-negative number.',
                ], 400)];
            } else {
                $fields['hourlyRate'] = number_format((float) $raw, 2, '.', '');
            }
        }

        return [$fields, null];
    }

    private function serialize(CoachProfile $profile): array
    {
        $user = $profile->getUser();

        return [
            'id' => (string) $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'role' => 'coach',
            'status' => $user->getStatus()->value,
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'specialty' => $profile->getSpecialty(),
            'bio' => $profile->getBio(),
            'hourlyRate' => $profile->getHourlyRate(),
            'branchIds' => array_map(
                fn ($assignment) => (string) $assignment->getBranch()->getId(),
                $user->getBranchAssignments()->toArray(),
            ),
            'branches' => array_map(
                fn ($assignment) => [
                    'id' => (string) $assignment->getBranch()->getId(),
                    'name' => $assignment->getBranch()->getName(),
                ],
                $user->getBranchAssignments()->toArray(),
            ),
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

    private function conflict(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'conflict', 'message' => $message], 409);
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

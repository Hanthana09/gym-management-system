<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\InvitationRole;
use App\Gym\GymProvisioningService;
use App\Invitation\InvitationNotRespondableException;
use App\Invitation\InvitationService;
use App\Repository\InvitationRepository;
use App\Security\Voter\InvitationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class InvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly InvitationRepository $invitationRepository,
        private readonly GymProvisioningService $gymProvisioning,
    ) {
    }

    #[Route('/invitations', name: 'invitations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $destination = trim((string) ($data['destination'] ?? ''));
        $role = InvitationRole::tryFrom((string) ($data['role'] ?? ''));

        if ($destination === '' || $role === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'A destination and a role (coach or member) are required.'], 400);
        }

        // architecture doc §9.1's InvitationVoter::SEND expects an actual
        // Invitation subject; ensureGymForOwner() always returns a gym owned
        // by $user, so this candidate exercises the real check (an Owner
        // always passes, a Coach/Member always fails isOwner()).
        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $candidate = new Invitation($gym, $user, null, null, null, $role, new \DateTimeImmutable('+7 days'));
        if (!$this->isGranted(InvitationVoter::SEND, $candidate)) {
            return $this->forbidden();
        }

        $result = $this->invitations->sendInvitation($user, $destination, $role);

        return new JsonResponse(
            $this->serialize($result['invitation']),
            $result['created'] ? 201 : 200,
        );
    }

    /**
     * roadmap Phase 9.1 (GTM Pillar A): CSV in, per-row report out. The
     * SEND permission check runs once, up front — it's the same Owner and
     * the same gym for every row in a batch, so InvitationVoter::SEND's
     * outcome can't differ row to row (unlike the per-row destination/role
     * validation below, which is exactly the point of this endpoint).
     * Every row still goes through InvitationService::sendInvitation()
     * unchanged — there is no path here that sets User.status directly.
     */
    #[Route('/invitations/bulk', name: 'invitations_bulk_create', methods: ['POST'])]
    public function bulkCreate(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $csv = (string) ($data['csv'] ?? '');
        if (trim($csv) === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'csv is required.'], 400);
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $candidate = new Invitation($gym, $user, null, null, null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));
        if (!$this->isGranted(InvitationVoter::SEND, $candidate)) {
            return $this->forbidden();
        }

        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'No data rows found in the CSV.'], 400);
        }

        $results = $this->invitations->bulkImport($user, $rows);

        return new JsonResponse([
            'results' => $results,
            'summary' => [
                'created' => count(array_filter($results, fn (array $r) => $r['outcome'] === 'created')),
                'duplicate' => count(array_filter($results, fn (array $r) => $r['outcome'] === 'duplicate')),
                'invalid' => count(array_filter($results, fn (array $r) => $r['outcome'] === 'invalid')),
            ],
        ], 201);
    }

    #[Route('/invitations/me', name: 'invitations_me', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $invitations = array_map(
            fn (Invitation $invitation) => $this->serialize($invitation),
            $this->invitations->listForUser($user),
        );

        return new JsonResponse(['invitations' => $invitations]);
    }

    #[Route('/invitations/{id}/approve', name: 'invitations_approve', methods: ['PATCH'])]
    public function approve(string $id): JsonResponse
    {
        return $this->respond($id, approve: true);
    }

    #[Route('/invitations/{id}/decline', name: 'invitations_decline', methods: ['PATCH'])]
    public function decline(string $id): JsonResponse
    {
        return $this->respond($id, approve: false);
    }

    private function respond(string $id, bool $approve): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $invitation = $this->invitationRepository->find($id);
        if ($invitation === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Invitation not found.'], 404);
        }

        // functional requirements §2.2: must hold even if a different user
        // somehow knows this invitation's id.
        if (!$this->isGranted(InvitationVoter::RESPOND, $invitation)) {
            return $this->forbidden();
        }

        try {
            $approve ? $this->invitations->approve($invitation) : $this->invitations->decline($invitation);
        } catch (InvitationNotRespondableException $exception) {
            return new JsonResponse([
                'error' => 'invitation_' . $exception->reason,
                'message' => match ($exception->reason) {
                    'expired' => 'This invitation has expired.',
                    'already_responded' => 'This invitation has already been responded to.',
                    default => 'This invitation cannot be responded to.',
                },
            ], 409);
        }

        return new JsonResponse($this->serialize($invitation));
    }

    /**
     * Header-driven, not position-driven — "inconsistent columns" (roadmap
     * Phase 9.1) means real spreadsheets vary in column order/naming, so
     * this matches by header name (case-insensitive, a couple of common
     * aliases) rather than assuming a fixed column order. Ragged rows
     * (fewer fields than the header) are tolerated: missing trailing
     * fields just come back empty, they don't shift later columns or
     * throw.
     *
     * @return array<array{name: ?string, email: ?string, phone: ?string, role: ?string}>
     */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($csv)) ?: [],
            fn (string $line) => trim($line) !== '',
        ));
        if ($lines === []) {
            return [];
        }

        $header = array_map(fn (string $h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\') ?: []);
        $columns = [
            'name' => $this->findColumn($header, ['name']),
            'email' => $this->findColumn($header, ['email', 'e-mail']),
            'phone' => $this->findColumn($header, ['phone', 'phone number', 'mobile']),
            'role' => $this->findColumn($header, ['role', 'type']),
        ];

        $rows = [];
        foreach ($lines as $line) {
            $fields = str_getcsv($line, ',', '"', '\\') ?: [];
            $rows[] = [
                'name' => $this->fieldAt($fields, $columns['name']),
                'email' => $this->fieldAt($fields, $columns['email']),
                'phone' => $this->fieldAt($fields, $columns['phone']),
                'role' => $this->fieldAt($fields, $columns['role']),
            ];
        }

        return $rows;
    }

    /** @param string[] $header @param string[] $aliases */
    private function findColumn(array $header, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $index = array_search($alias, $header, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /** @param string[] $fields */
    private function fieldAt(array $fields, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        $value = trim($fields[$index] ?? '');

        return $value === '' ? null : $value;
    }

    private function serialize(Invitation $invitation): array
    {
        return [
            'id' => (string) $invitation->getId(),
            'gymId' => (string) $invitation->getGym()->getId(),
            'destination' => $invitation->getEmail() ?? $invitation->getPhone(),
            'role' => $invitation->getRole()->value,
            'status' => $invitation->getStatus()->value,
            'createdAt' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'respondedAt' => $invitation->getRespondedAt()?->format(\DateTimeInterface::ATOM),
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

    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }
}

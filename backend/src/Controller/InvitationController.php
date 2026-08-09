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

<?php

namespace App\Controller;

use App\Entity\Announcement;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\Audience;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Notification\AnnouncementService;
use App\Notification\NotificationService;
use App\Repository\BranchRepository;
use App\Repository\GymRepository;
use App\Repository\NotificationRepository;
use App\Security\Voter\AnnouncementVoter;
use App\Security\Voter\NotificationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AnnouncementService $announcements,
        private readonly NotificationRepository $notificationRepository,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly GymRepository $gyms,
        private readonly BranchRepository $branches,
    ) {
    }

    #[Route('/notifications', name: 'notifications_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $notifications = $this->notifications->listForUser($user);

        return new JsonResponse([
            'notifications' => array_map(fn (Notification $n) => $this->serialize($n), $notifications),
            'unreadCount' => count(array_filter($notifications, fn (Notification $n) => !$n->isRead())),
        ]);
    }

    #[Route('/notifications/{id}/read', name: 'notifications_read', methods: ['PATCH'])]
    public function markRead(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $notification = $this->notificationRepository->find($id);
        if ($notification === null) {
            return $this->notFound('Notification not found.');
        }

        if (!$this->isGranted(NotificationVoter::MARK_READ, $notification)) {
            return $this->forbidden();
        }

        $this->notifications->markRead($notification);

        return new JsonResponse($this->serialize($notification));
    }

    /**
     * functional requirements §6.2/§6.3: Owner posts gym-wide, Coach posts
     * to own clients only — the frontend never offers a Coach the
     * gym-wide option, but AnnouncementVoter (copied verbatim) enforces it
     * here regardless (architecture doc §7: "every non-Owner endpoint
     * enforces row-level scoping in the service layer, not just the
     * controller guard").
     */
    #[Route('/announcements', name: 'announcements_create', methods: ['POST'])]
    public function createAnnouncement(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $data = $this->decode($request);
        $body = trim((string) ($data['body'] ?? ''));
        $audience = Audience::tryFrom((string) ($data['audience'] ?? ''));

        if ($body === '' || $audience === null) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'body and a valid audience (gym_wide or own_clients) are required.',
            ], 400);
        }

        // Gym resolution here is just object construction for the Voter
        // candidate below, not itself a security decision — a Member (or
        // any other non-Owner) always fails AnnouncementVoter regardless
        // of which gym object this ends up being, so it's safe to fall
        // back to "the only gym" rather than 404 before the Voter even
        // runs (which would leak "no announcement rights" as a 404
        // instead of the correct 403).
        $gym = $user->getRole() === UserRole::OWNER
            ? $this->gymProvisioning->ensureGymForOwner($user)
            : $this->gyms->findTheOnlyGym();
        if ($gym === null) {
            return $this->notFound('No gym found for this account.');
        }

        // roadmap Phase 16 / functional requirements §14: an explicit
        // branchId targets one branch; omitting it means gym-wide — unlike
        // every other Phase 16 retrofit, there's no "default to primary"
        // here, since null vs. a branch id are both genuine, different
        // choices an Owner can make (functional requirements §14.5's same
        // "all branches" vs. "one branch" distinction for reports).
        $branchId = isset($data['branchId']) ? (string) $data['branchId'] : null;
        $branch = null;
        if ($branchId !== null && $branchId !== '') {
            $branch = $this->branches->find($branchId);
            if ($branch === null || $branch->getGym() !== $gym) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'branchId does not belong to this gym.'], 400);
            }
        }

        // architecture doc §9.1's AnnouncementVoter::CREATE expects an
        // actual Announcement subject; this candidate exercises the real
        // check (Owner always passes for gym_wide, Coach only for
        // own_clients, Member always fails).
        $candidate = new Announcement($gym, $user, $body, $audience, $branch);
        if (!$this->isGranted(AnnouncementVoter::CREATE, $candidate)) {
            return $this->forbidden();
        }

        $result = $this->announcements->publish($candidate);

        return new JsonResponse([
            'id' => (string) $result['announcement']->getId(),
            'body' => $result['announcement']->getBody(),
            'audience' => $result['announcement']->getAudience()->value,
            'branchId' => $result['announcement']->getBranch() !== null ? (string) $result['announcement']->getBranch()->getId() : null,
            'createdAt' => $result['announcement']->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'recipientCount' => $result['recipientCount'],
        ], 201);
    }

    private function serialize(Notification $notification): array
    {
        return [
            'id' => (string) $notification->getId(),
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'type' => $notification->getType()->value,
            'sourceRole' => $notification->getSourceRole()?->value,
            'read' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
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

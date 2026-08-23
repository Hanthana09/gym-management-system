<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationService;
use App\Repository\NotificationRepository;
use App\Security\Voter\NotificationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * gym-management-dashboard-redesign.md Phase 0: the final, unambiguous
 * notification path scheme, replacing the old `/api/notifications` +
 * the API Platform resource's double-prefixed (and therefore dead)
 * `/api/api/v1/notifications`:
 *   - `GET /api/v1/notifications` — list, API Platform
 *     (Entity\Notification's own #[ApiResource], now correctly single-
 *     prefixed after config/routes/api_platform.yaml's redundant
 *     `prefix: /api` was removed).
 *   - `PATCH /api/v1/notifications/{id}` — mark one read, hand-written.
 *     Not API Platform: `read` only has a one-way `markRead()` domain
 *     method, no `setRead()` — same reasoning already documented on
 *     Notification.php/Invoice.php/PtSession's status Patch.
 *   - `POST /api/v1/notifications/mark-all-read` — hand-written (bulk
 *     action, nothing for API Platform to express here at all).
 *   - `GET /api/v1/notifications/unread-count` — hand-written,
 *     lightweight (used by the four dashboard DTOs server-side, and
 *     available standalone).
 *
 * Announcement composition (`POST /api/announcements`) moved out to its
 * own AnnouncementController — a different concern that used to just
 * share a file, not this path prefix.
 */
#[Route('/api/v1/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    #[Route('/{id}', name: 'notifications_mark_read', methods: ['PATCH'])]
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

    #[Route('/mark-all-read', name: 'notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $this->notifications->markAllReadForUser($user);

        return new JsonResponse(['message' => 'All notifications marked read.']);
    }

    /**
     * `priority` is required here: API Platform auto-adds an implicit
     * item Get operation for Notification (`/api/v1/notifications/{id}
     * .{_format}`, GET) even though only GetCollection is declared on
     * the entity — without a higher priority, that wildcard route is
     * matched first and swallows this literal path (`unread-count`
     * resolved as `{id}`), 404ing via API Platform's NotExposedAction
     * instead of ever reaching this controller. Confirmed empirically
     * via a live request, not just route inspection. `mark-all-read`
     * (POST) needs no such fix — API Platform's implicit item operation
     * is GET-only.
     */
    #[Route('/unread-count', name: 'notifications_unread_count', methods: ['GET'], priority: 10)]
    public function unreadCount(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        return new JsonResponse(['unreadCount' => $this->notificationRepository->countUnreadForUser($user)]);
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
}

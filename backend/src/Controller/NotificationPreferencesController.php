<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

/**
 * roadmap Phase 15.3 / architecture doc §7: any authenticated user
 * manages their own preferences — no Voter needed, "own account" is the
 * whole scope (same reasoning as GET /members/me/* endpoints elsewhere).
 */
#[Route('/api')]
class NotificationPreferencesController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/users/me/notification-preferences', name: 'users_me_notification_preferences', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
        }

        $data = $this->decode($request);
        if (!array_key_exists('whatsappOptIn', $data)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'whatsappOptIn is required.',
            ], 400);
        }

        $user->setWhatsappOptIn((bool) $data['whatsappOptIn']);
        $this->em->flush();

        return new JsonResponse(['whatsappOptIn' => $user->isWhatsappOptIn()]);
    }

    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException) {
            return [];
        }
    }
}

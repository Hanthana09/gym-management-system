<?php

namespace App\Controller;

use App\Entity\User;
use App\PasswordReset\AdminSetPasswordService;
use App\Repository\UserRepository;
use App\Security\Voter\PasswordManagementVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * gym-management-password-auth.md §4: POST /users/{id}/set-password —
 * Owner-only, PasswordManagementVoter-scoped to the target user.
 */
#[Route('/api')]
class UserAccountController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminSetPasswordService $adminSetPasswordService,
    ) {
    }

    #[Route('/users/{id}/set-password', name: 'users_set_password', methods: ['POST'])]
    public function setPassword(string $id, Request $request): JsonResponse
    {
        $owner = $this->getUser();
        if (!$owner instanceof User) {
            return $this->unauthenticated();
        }

        $target = $this->users->find($id);
        if ($target === null) {
            return $this->notFound('User not found.');
        }

        if (!$this->isGranted(PasswordManagementVoter::SET_PASSWORD, $target)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $plainPassword = array_key_exists('password', $data) && $data['password'] !== null
            ? (string) $data['password']
            : null;

        if ($plainPassword !== null && strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
            ], 400);
        }

        $generatedPassword = $this->adminSetPasswordService->setPassword($target, $owner, $plainPassword);

        return new JsonResponse(['password' => $generatedPassword]);
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

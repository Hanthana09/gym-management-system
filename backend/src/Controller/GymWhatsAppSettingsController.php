<?php

namespace App\Controller;

use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\GymRepository;
use App\Security\Voter\GymVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Owner-facing admin config for the WhatsApp channel — a gym-wide master
 * switch plus credential setup, on top of the per-user `whatsappOptIn`
 * toggle from the original 15.3 build (`PATCH
 * /users/me/notification-preferences`). Reuses `GymVoter::MANAGE`, same
 * as branding — this is just more fields on the same Gym entity Owners
 * already manage.
 */
class GymWhatsAppSettingsController extends AbstractController
{
    public function __construct(
        private readonly GymRepository $gyms,
        private readonly GymProvisioningService $provisioning,
    ) {
    }

    #[Route('/gym/whatsapp-settings', name: 'gym_whatsapp_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        // Unlike branding (visible to every role — it's shown on the nav
        // header), this admin section is Owner-only, so a direct role
        // check stands in for GymVoter here: the Voter can't vote on a
        // Gym that doesn't exist yet, and an Owner who hasn't set up
        // their gym should still see "not configured" defaults, not a
        // 403 — GET must never provision as a side effect just to make
        // the Voter checkable.
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $gym = $this->gyms->findTheOnlyGym();

        return new JsonResponse($gym !== null ? $this->serialize($gym) : ['enabled' => false, 'phoneNumberId' => null, 'accessTokenSet' => false]);
    }

    #[Route('/gym/whatsapp-settings', name: 'gym_whatsapp_settings_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        // Lazily provisioned, same as branding — an Owner configuring
        // WhatsApp before ever sending an invite or creating a plan is
        // entirely plausible.
        $gym = $this->provisioning->ensureGymForOwner($user);

        if (!$this->isGranted(GymVoter::MANAGE, $gym)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);

        if (array_key_exists('enabled', $data)) {
            $gym->setWhatsappEnabled((bool) $data['enabled']);
        }

        if (array_key_exists('accessToken', $data)) {
            $token = trim((string) $data['accessToken']);
            $gym->setWhatsappAccessToken($token === '' ? null : $token);
        }

        if (array_key_exists('phoneNumberId', $data)) {
            $phoneNumberId = trim((string) $data['phoneNumberId']);
            $gym->setWhatsappPhoneNumberId($phoneNumberId === '' ? null : $phoneNumberId);
        }

        // Turning the master switch on without credentials configured is
        // a no-op the moment a message actually tries to send (the
        // handler/sender both re-check), but rejecting it here gives the
        // Owner an immediate, specific error instead of a silently
        // swallowed send later.
        if ($gym->isWhatsappEnabled() && !$gym->isWhatsappConfigured()) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'Add an access token and phone number ID before enabling WhatsApp.',
            ], 400);
        }

        $this->gyms->getEntityManager()->flush();

        return new JsonResponse($this->serialize($gym));
    }

    private function serialize(Gym $gym): array
    {
        return [
            'enabled' => $gym->isWhatsappEnabled(),
            'phoneNumberId' => $gym->getWhatsappPhoneNumberId(),
            // The access token itself is never returned once set — only
            // whether one is on file, so the UI can show "configured"
            // without round-tripping the secret back to the browser.
            'accessTokenSet' => $gym->getWhatsappAccessToken() !== null,
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
        } catch (\JsonException) {
            return [];
        }
    }
}

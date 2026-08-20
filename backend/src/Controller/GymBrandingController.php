<?php

namespace App\Controller;

use App\Entity\User;
use App\Gym\GymProvisioningService;
use App\Repository\GymRepository;
use App\Security\Voter\GymVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use League\Flysystem\FilesystemOperator;

/**
 * roadmap Phase 15.2 / architecture doc §6.11, §7. `PATCH /gym/branding`
 * reuses `GymVoter::MANAGE` as-is (the doc's explicit "no new Voter
 * needed" note — GymVoter itself still had to be built fresh, see its
 * docblock). `GET /gym/branding` isn't in the doc's endpoint list but is
 * required by functional requirements §12.1 ("when a Member views the
 * app, the branding appears on the nav header") — every authenticated
 * role needs to read it, not just the Owner who sets it.
 *
 * `name` was added directly on request, on this same endpoint rather
 * than a new one — it isn't a "branding/white-label" concern the way
 * logo/brandColor are (DESIGN-SYSTEM.md §4.1's bounded-customization
 * rule doesn't apply to it at all), but it's the same "Owner-writable,
 * everyone-readable, shown in NavShell" shape, so it reuses the same
 * Voter check and read-for-all GET rather than standing up a parallel
 * identity-settings endpoint for one field.
 *
 * `gymCode` briefly lived here too (gym-management-member-profile-
 * extension.md §3/§6.1) but was relocated to GymMemberIdSettingsController
 * once the "editable/manual Member ID mode" follow-up feature gave it a
 * real sibling setting (memberIdMode) — that's its own concern, not
 * branding, same reasoning WhatsApp settings got their own controller
 * instead of piling onto this one.
 */
#[Route('/api')]
class GymBrandingController extends AbstractController
{
    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly GymRepository $gyms,
        private readonly GymProvisioningService $provisioning,
        #[Autowire(service: 'gym_logos.storage')]
        private readonly FilesystemOperator $logos,
    ) {
    }

    #[Route('/gym/branding', name: 'gym_branding_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $gym = $this->gyms->findTheOnlyGym();

        return new JsonResponse([
            'name' => $gym?->getName(),
            'logoUrl' => $gym?->getLogoUrl(),
            'brandColor' => $gym?->getBrandColor(),
        ]);
    }

    #[Route('/gym/branding', name: 'gym_branding_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        // Lazily provisioned (same pattern as every other Owner action,
        // GymProvisioningService's own docblock) — an Owner setting
        // branding before ever sending an invite or creating a plan is
        // entirely plausible, and shouldn't 404.
        $gym = $this->provisioning->ensureGymForOwner($user);

        if (!$this->isGranted(GymVoter::MANAGE, $gym)) {
            return $this->forbidden();
        }

        if ($request->request->has('name')) {
            $name = trim((string) $request->request->get('name'));
            if ($name === '') {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'name cannot be empty.'], 400);
            }
            $gym->setName($name);
        }

        if ($request->request->has('brandColor')) {
            $brandColor = (string) $request->request->get('brandColor');
            if ($brandColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)) {
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'message' => 'brandColor must be a hex color like #1A2B3C.',
                ], 400);
            }
            $gym->setBrandColor($brandColor === '' ? null : $brandColor);
        }

        $logo = $request->files->get('logo');
        if ($logo instanceof UploadedFile) {
            if (!$logo->isValid()) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'Logo upload failed.'], 400);
            }
            if (!in_array($logo->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'message' => 'Logo must be a PNG, JPEG, or WebP image.',
                ], 400);
            }
            if ($logo->getSize() === null || $logo->getSize() > self::MAX_LOGO_BYTES) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'Logo must be under 2MB.'], 400);
            }

            $extension = match ($logo->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $filename = $gym->getId() . '.' . $extension;
            $this->logos->write($filename, file_get_contents($logo->getPathname()));
            $gym->setLogoUrl('/uploads/gym-logos/' . $filename);
        }

        $this->em()->flush();

        return new JsonResponse([
            'name' => $gym->getName(),
            'logoUrl' => $gym->getLogoUrl(),
            'brandColor' => $gym->getBrandColor(),
        ]);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        return $this->gyms->getEntityManager();
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403);
    }
}

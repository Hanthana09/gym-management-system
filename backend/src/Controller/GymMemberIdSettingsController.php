<?php

namespace App\Controller;

use App\Entity\Gym;
use App\Entity\User;
use App\Enum\MemberIdMode;
use App\Enum\UserRole;
use App\Gym\GymProvisioningService;
use App\Repository\GymRepository;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\GymVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Follow-up feature: "editable/manual Member ID mode" — lets an Owner
 * switch a gym between Setly's auto-generated `{gymCode}-{0001}`
 * sequence and entering the gym's own member numbers by hand. Writing
 * this policy (PATCH) is Owner-only (GymVoter::MANAGE), mirroring
 * GymWhatsAppSettingsController's shape — this is gym-wide admin config,
 * not something every role needs, unlike branding. Reading it (GET) is
 * Owner+Staff, wider than WhatsApp settings' Owner-only GET: Staff needs
 * to know the mode to render the "Add Member"/profile-edit form
 * correctly (MemberVoter::EDIT_PROFILE grants Staff that capability
 * too), so this is closer to branding's "everyone who needs to read it,
 * write stays Owner-only" split — just not literally everyone, since
 * Coach/Member have no reason to ever see this.
 */
#[Route('/api')]
class GymMemberIdSettingsController extends AbstractController
{
    public function __construct(
        private readonly GymRepository $gyms,
        private readonly GymProvisioningService $provisioning,
        private readonly MemberProfileRepository $memberProfiles,
    ) {
    }

    #[Route('/gym/member-id-settings', name: 'gym_member_id_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        // Read side is Owner+Staff, unlike WhatsApp settings' Owner-only
        // GET — Staff needs to know the gym's mode to render the "Add
        // Member"/profile-edit form correctly (memberId field required
        // vs. absent) now that MemberVoter::EDIT_PROFILE grants Staff
        // that capability too. Write stays Owner-only below (GymVoter::
        // MANAGE) — only the read side widened.
        if ($user->getRole() !== UserRole::OWNER && $user->getRole() !== UserRole::STAFF) {
            return $this->forbidden();
        }

        $gym = $this->gyms->findTheOnlyGym();

        return new JsonResponse($gym !== null
            ? $this->serialize($gym)
            : ['mode' => MemberIdMode::AUTO->value, 'gymCode' => null]);
    }

    #[Route('/gym/member-id-settings', name: 'gym_member_id_settings_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        // Role check before provisioning — GymVoter::MANAGE requires
        // isOwner() too, so a non-Owner is denied either way, but
        // ensureGymForOwner($user) would otherwise create a bogus Gym
        // "owned" by whoever called this first, as a side effect of a
        // request that should just be denied outright.
        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $gym = $this->provisioning->ensureGymForOwner($user);

        if (!$this->isGranted(GymVoter::MANAGE, $gym)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);

        if (array_key_exists('mode', $data)) {
            $mode = MemberIdMode::tryFrom((string) $data['mode']);
            if ($mode === null) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'mode must be one of: auto, manual.'], 400);
            }

            if ($mode !== $gym->getMemberIdMode() && $this->memberProfiles->existsForGym($gym)) {
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'message' => "Member ID mode can't be changed once this gym has members.",
                ], 400);
            }

            $gym->setMemberIdMode($mode);
        }

        if (array_key_exists('gymCode', $data)) {
            $gymCode = strtoupper(trim((string) $data['gymCode']));
            if ($gymCode !== '' && !preg_match('/^[A-Z0-9]{2,8}$/', $gymCode)) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'gymCode must be 2-8 letters/digits.'], 400);
            }
            $existing = $gymCode === '' ? null : $this->gyms->findOneBy(['gymCode' => $gymCode]);
            if ($existing !== null && $existing !== $gym) {
                return new JsonResponse(['error' => 'invalid_request', 'message' => 'gymCode is already in use.'], 400);
            }
            $gym->setGymCode($gymCode === '' ? null : $gymCode);
        }

        $this->gyms->getEntityManager()->flush();

        return new JsonResponse($this->serialize($gym));
    }

    private function serialize(Gym $gym): array
    {
        return [
            'mode' => $gym->getMemberIdMode()->value,
            'gymCode' => $gym->getGymCode(),
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

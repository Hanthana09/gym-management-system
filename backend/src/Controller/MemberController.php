<?php

namespace App\Controller;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\UserRole;
use App\Membership\MembershipService;
use App\Repository\CoachProfileRepository;
use App\Repository\MemberProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * architecture doc §7: GET /members (Owner). A plain role gate rather
 * than a MemberVoter check — MemberVoter (§9.1, already implemented) is
 * an object-level check ("can I see THIS member"), and this endpoint
 * returns the whole roster with nothing to check a single subject
 * against. The doc itself scopes this endpoint to "(Owner)"
 * unconditionally, which is exactly what this does; MemberVoter is used
 * as-is wherever a specific MemberProfile is the actual subject (a
 * future per-member action, e.g. suspend/remove).
 *
 * Broadened beyond the doc's literal "members only" scope on direct
 * request: the Owner's roster page needs coaches alongside members (one
 * place to see everyone at the gym), so each entry now carries a `role`
 * field to tell them apart. Coaches never have a Membership, so their
 * entries always serialize `membership: null`.
 */
class MemberController extends AbstractController
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MembershipService $memberships,
    ) {
    }

    #[Route('/members', name: 'members_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        if ($user->getRole() !== UserRole::OWNER) {
            return $this->forbidden();
        }

        $roster = [
            ...array_map(
                fn (MemberProfile $profile) => $this->serializeMember($profile),
                $this->memberProfiles->findAllWithUser(),
            ),
            ...array_map(
                fn (CoachProfile $profile) => $this->serializeCoach($profile),
                $this->coachProfiles->findAllWithUser(),
            ),
        ];

        return new JsonResponse(['members' => $roster]);
    }

    private function serializeMember(MemberProfile $profile): array
    {
        $user = $profile->getUser();
        // MembershipService::getMembershipForMember() (not the raw
        // repository call) lazily flips a past-end-date membership to
        // 'expired' before returning it — same pattern the Member's own
        // "My membership" view already relies on for an accurate status.
        $membership = $this->memberships->getMembershipForMember($profile);

        return [
            'id' => (string) $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'role' => 'member',
            'status' => $user->getStatus()->value,
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'membership' => $membership !== null ? $this->serializeMembership($membership) : null,
        ];
    }

    private function serializeCoach(CoachProfile $profile): array
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
            'membership' => null,
        ];
    }

    private function serializeMembership(Membership $membership): array
    {
        return [
            'planName' => $membership->getPlan()->getName(),
            'status' => $membership->getStatus()->value,
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
}

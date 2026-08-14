<?php

namespace App\Controller;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Member\MemberService;
use App\Membership\MembershipService;
use App\Repository\CoachProfileRepository;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\MemberVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * roadmap Phase 15.1: list() also accepts Staff — architecture doc §7's
 * "GET /members (Owner, Staff — read-only for Staff)." Read-only means
 * Staff simply has no path to updateStatus() below (MemberVoter::MANAGE
 * has no isStaff branch, see that Voter's docblock) — list() itself
 * doesn't distinguish "read-only" any further than "can call this
 * endpoint at all."
 */
class MemberController extends AbstractController
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MembershipService $memberships,
        private readonly MemberService $members,
    ) {
    }

    #[Route('/members', name: 'members_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        if ($user->getRole() !== UserRole::OWNER && $user->getRole() !== UserRole::STAFF) {
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

    /**
     * architecture doc §7: PATCH /members/:id/status (Owner — suspend/
     * remove). Only active|suspended are ever client-selectable —
     * pending_approval is owned entirely by the invitation flow (§6.7)
     * and is never a valid target of this endpoint.
     */
    #[Route('/members/{id}/status', name: 'members_update_status', methods: ['PATCH'])]
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        if (!$this->isGranted(MemberVoter::MANAGE, $member)) {
            return $this->forbidden();
        }

        $data = $this->decode($request);
        $statusValue = (string) ($data['status'] ?? '');
        $newStatus = UserStatus::tryFrom($statusValue);
        if ($newStatus === null || !in_array($newStatus, [UserStatus::ACTIVE, UserStatus::SUSPENDED], true)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'status must be one of: active, suspended.',
            ], 400);
        }

        $this->members->updateStatus($member, $newStatus, $user);

        return new JsonResponse($this->serializeMember($member));
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

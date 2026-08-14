<?php

namespace App\Controller;

use App\Attendance\AttendanceService;
use App\Attendance\CheckInBlockedException;
use App\Entity\AttendanceLog;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\AttendanceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class AttendanceController extends AbstractController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly MemberProfileRepository $memberProfiles,
    ) {
    }

    #[Route('/members/me/checkin', name: 'members_me_checkin', methods: ['POST'])]
    public function checkIn(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->findOneByUser($user);
        if ($member === null) {
            return $this->notFound('No member profile found for this account.');
        }

        if (!$this->isGranted(AttendanceVoter::CHECK_IN, $member)) {
            return $this->forbidden();
        }

        try {
            $log = $this->attendance->checkIn($member);
        } catch (CheckInBlockedException $exception) {
            return new JsonResponse([
                'error' => 'checkin_blocked',
                'reason' => $exception->reason->value,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return new JsonResponse($this->serializeLog($log), 201);
    }

    /** architecture doc §7: front-desk variant — Owner or Staff checking a member in on their behalf (roadmap Phase 15.1). */
    #[Route('/members/{id}/checkin', name: 'members_checkin_front_desk', methods: ['POST'])]
    public function checkInFrontDesk(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        if (!$this->isGranted(AttendanceVoter::CHECK_IN, $member)) {
            return $this->forbidden();
        }

        try {
            $log = $this->attendance->checkIn($member, CheckInMethod::FRONT_DESK);
        } catch (CheckInBlockedException $exception) {
            return new JsonResponse([
                'error' => 'checkin_blocked',
                'reason' => $exception->reason->value,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return new JsonResponse($this->serializeLog($log), 201);
    }

    private function serializeLog(AttendanceLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
            'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
            'method' => $log->getMethod()->value,
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

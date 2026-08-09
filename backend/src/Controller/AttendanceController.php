<?php

namespace App\Controller;

use App\Attendance\AttendanceService;
use App\Attendance\CheckInBlockedException;
use App\Entity\AttendanceLog;
use App\Entity\User;
use App\Gym\GymProvisioningService;
use App\Repository\AttendanceLogRepository;
use App\Repository\MemberProfileRepository;
use App\Security\Voter\AttendanceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AttendanceController extends AbstractController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly GymProvisioningService $gymProvisioning,
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

    /** functional requirements §4.2: live counter (call with no params) + date-range-filterable report. */
    #[Route('/reports/attendance', name: 'reports_attendance', methods: ['GET'])]
    public function report(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        if (!$this->isGranted(AttendanceVoter::VIEW_ALL)) {
            return $this->forbidden();
        }

        $gym = $this->gymProvisioning->ensureGymForOwner($user);

        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');
        $from = $fromParam !== null ? new \DateTimeImmutable($fromParam) : new \DateTimeImmutable('today');
        // Inclusive end date: querying up to the end of the given day.
        $to = ($toParam !== null ? new \DateTimeImmutable($toParam) : new \DateTimeImmutable('today'))->modify('+1 day');

        $entries = $this->attendanceLogs->findByDateRange($from, $to);

        return new JsonResponse([
            'gymId' => (string) $gym->getId(),
            'count' => $this->attendanceLogs->countSince(new \DateTimeImmutable('today')),
            'entries' => array_map(fn (AttendanceLog $log) => [
                'id' => (string) $log->getId(),
                'memberName' => $log->getMember()->getUser()->getName(),
                'checkInAt' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
                'method' => $log->getMethod()->value,
            ], $entries),
        ]);
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

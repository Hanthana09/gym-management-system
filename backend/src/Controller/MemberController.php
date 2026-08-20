<?php

namespace App\Controller;

use App\Entity\AttendanceLog;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\PtSession;
use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Enum\Gender;
use App\Enum\MemberIdMode;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Gym\GymProvisioningService;
use App\Member\MemberCreationService;
use App\Member\MemberService;
use App\Membership\MembershipService;
use App\Repository\AttendanceLogRepository;
use App\Repository\CoachProfileRepository;
use App\Repository\GymRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\PtSessionRepository;
use App\Repository\WorkoutAssignmentRepository;
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
 * has no isStaff branch, see that Voter's docblock).
 *
 * roadmap Phase 16 update: for Staff specifically, the MEMBER entries in
 * this roster are now actually filtered through MemberVoter::VIEW (which
 * narrows to Staff's assigned branch(es) per that Voter's updated body) —
 * before this phase, "can call this endpoint at all" was the only check;
 * now the per-row visibility the Voter always implied is real. Coach
 * entries are left unfiltered — MemberVoter doesn't govern CoachProfile
 * subjects, and this phase's retrofit checklist doesn't ask for a
 * separate Staff-vs-coach-roster branch rule.
 */
#[Route('/api')]
class MemberController extends AbstractController
{
    private const DEFAULT_ATTENDANCE_PAGE_SIZE = 20;

    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MembershipService $memberships,
        private readonly MemberService $members,
        private readonly MemberCreationService $memberCreation,
        private readonly PtSessionRepository $ptSessions,
        private readonly WorkoutAssignmentRepository $workoutAssignments,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly GymRepository $gyms,
        private readonly GymProvisioningService $provisioning,
    ) {
    }

    /**
     * gym-management-member-profile-extension.md §4: manual walk-in
     * creation. Overrides FR §15.2's invite-only rule for this phase
     * only — see CLAUDE.md/FR doc's updated notes.
     *
     * Follow-up feature (editable/manual Member ID mode): widened from
     * Owner-only to Owner+Staff (MemberVoter::EDIT_PROFILE's scope —
     * front-desk registration is typically a Staff task; MemberVoter::
     * MANAGE, which gates suspend/reactivate, stays Owner-only and
     * untouched). Still a plain role gate, not a Voter call — no subject
     * exists yet to run EDIT_PROFILE against, same reasoning list()'s
     * gate already uses.
     */
    #[Route('/members', name: 'members_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }
        if ($user->getRole() !== UserRole::OWNER && $user->getRole() !== UserRole::STAFF) {
            return $this->forbidden();
        }

        $gym = $this->resolveGymForActingUser($user);
        if ($gym === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'No gym exists yet.'], 400);
        }

        $data = $this->decode($request);

        $name = trim((string) ($data['name'] ?? ''));
        $email = isset($data['email']) && $data['email'] !== '' ? (string) $data['email'] : null;
        $phone = isset($data['phone']) && $data['phone'] !== '' ? (string) $data['phone'] : null;
        if ($name === '' || ($email === null && $phone === null)) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'name and at least one of email/phone are required.',
            ], 400);
        }

        [$fields, $error] = $this->parseProfileFields($data, $gym, null, isCreate: true);
        if ($error !== null) {
            return $error;
        }

        $profile = $this->memberCreation->createWalkIn($gym, $name, $email, $phone, $user, $fields);

        return new JsonResponse($this->serializeMemberProfileDetail($profile), 201);
    }

    /**
     * gym-management-member-profile-extension.md §6.1: Owner resolves to
     * their own gym (lazily provisioned, same as every other Owner
     * action); Staff has no owned gym, so this falls back to the
     * single-gym-product convention used elsewhere for Staff/Coach
     * (GymRepository::findTheOnlyGym(), e.g. announcements) — returns
     * null only in the practically-unreachable case no gym exists yet
     * (a Staff account can't exist without having been invited by an
     * Owner, and sending an invitation already lazily provisions one).
     */
    private function resolveGymForActingUser(User $user): ?Gym
    {
        if ($user->getRole() === UserRole::OWNER) {
            return $this->gyms->findOneByOwner($user) ?? $this->provisioning->ensureGymForOwner($user);
        }

        return $this->gyms->findTheOnlyGym();
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

        $visibleMembers = array_filter(
            $this->memberProfiles->findAllWithUser(),
            fn (MemberProfile $profile) => $this->isGranted(MemberVoter::VIEW, $profile),
        );

        $roster = [
            ...array_map(fn (MemberProfile $profile) => $this->serializeMember($profile), $visibleMembers),
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

    /**
     * gym-management-member-profile-extension.md §4/§5's Profile tab.
     * MemberVoter::VIEW already grants a Member their own record (self
     * branch) as well as Owner/Staff — this is deliberately the same
     * endpoint for both, not a separate `/members/me`, since the Voter
     * already expresses exactly the access rule §4's CRUD table wants
     * ("new PII fields visible to the member themselves").
     */
    #[Route('/members/{id}', name: 'members_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        if (!$this->isGranted(MemberVoter::VIEW, $member)) {
            return $this->forbidden();
        }

        return new JsonResponse($this->serializeMemberProfileDetail($member));
    }

    /**
     * gym-management-member-profile-extension.md §4: dob/gender/address*,
     * plus `memberId` for gyms in manual mode (follow-up feature).
     *
     * Follow-up feature: gated by the new MemberVoter::EDIT_PROFILE
     * (Owner: own gym; Staff: gym-wide, unscoped) instead of MANAGE —
     * suspend/reactivate (updateStatus() above) still uses MANAGE and is
     * still Owner-only; this endpoint is data entry, not an account
     * status change, and was explicitly widened to Staff.
     */
    #[Route('/members/{id}', name: 'members_update_profile', methods: ['PATCH'])]
    public function updateProfile(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticated();
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return $this->notFound('Member not found.');
        }

        if (!$this->isGranted(MemberVoter::EDIT_PROFILE, $member)) {
            return $this->forbidden();
        }

        // Pre-backfill window (rare): fall back to the acting user's own
        // gym resolution, same null-safety reasoning as MemberVoter's
        // isOwner() branch.
        $gym = $member->getGym() ?? $this->resolveGymForActingUser($user);
        if ($gym === null) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'No gym exists yet.'], 400);
        }

        $data = $this->decode($request);

        [$fields, $error] = $this->parseProfileFields($data, $gym, $member, isCreate: false);
        if ($error !== null) {
            return $error;
        }

        $this->members->updateProfile($member, $fields, $user);

        return new JsonResponse($this->serializeMemberProfileDetail($member));
    }

    /**
     * gym-management-member-profile-extension.md §5's PT Schedule tab —
     * both booked 1:1 PtSessions and assigned WorkoutAssignments, since
     * the spec's own wording ("PT Session Schedule... the existing
     * workout-scheduling module") names both features under one label.
     * Owner/Staff only (spec §5: "not exposed on the Coach app") — a
     * plain role gate on top of MemberVoter::VIEW, same pairing the
     * other three detail routes below use.
     */
    #[Route('/members/{id}/pt-schedule', name: 'members_pt_schedule', methods: ['GET'])]
    public function ptSchedule(string $id): JsonResponse
    {
        [$member, $error] = $this->resolveForOwnerStaffDetail($id);
        if ($error !== null) {
            return $error;
        }

        return new JsonResponse([
            'ptSessions' => array_map(fn (PtSession $s) => $this->serializePtSession($s), $this->ptSessions->findForMember($member)),
            'workoutAssignments' => array_map(
                fn (WorkoutAssignment $a) => $this->serializeWorkoutAssignment($a),
                $this->workoutAssignments->findByMemberAndStatus($member->getUser(), null),
            ),
        ]);
    }

    /** gym-management-member-profile-extension.md §5's Attendance tab — paginated, most-recent-first. */
    #[Route('/members/{id}/attendance', name: 'members_attendance', methods: ['GET'])]
    public function attendance(string $id, Request $request): JsonResponse
    {
        [$member, $error] = $this->resolveForOwnerStaffDetail($id);
        if ($error !== null) {
            return $error;
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = self::DEFAULT_ATTENDANCE_PAGE_SIZE;

        return new JsonResponse([
            'logs' => array_map(
                fn (AttendanceLog $log) => $this->serializeAttendanceLog($log),
                $this->attendanceLogs->findPaginatedForMember($member, $page, $perPage),
            ),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $this->attendanceLogs->countForMember($member),
        ]);
    }

    /**
     * gym-management-member-profile-extension.md §5's Payment History
     * tab (§6.5 dependency: real billing is Phase 10). Explicit
     * "not yet available" contract, not an empty array pretending to be
     * real data — `available`/`payments` are Phase-10-compatible so a
     * real implementation slots in without a response-shape break.
     */
    #[Route('/members/{id}/payments', name: 'members_payments', methods: ['GET'])]
    public function payments(string $id): JsonResponse
    {
        [, $error] = $this->resolveForOwnerStaffDetail($id);
        if ($error !== null) {
            return $error;
        }

        return new JsonResponse([
            'available' => false,
            'reason' => 'not_yet_available',
            'message' => "Billing isn't enabled for this gym yet.",
            'payments' => [],
        ]);
    }

    /** @return array{0: ?MemberProfile, 1: ?JsonResponse} */
    private function resolveForOwnerStaffDetail(string $id): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return [null, $this->unauthenticated()];
        }
        if ($user->getRole() !== UserRole::OWNER && $user->getRole() !== UserRole::STAFF) {
            return [null, $this->forbidden()];
        }

        $member = $this->memberProfiles->find($id);
        if ($member === null) {
            return [null, $this->notFound('Member not found.')];
        }

        if (!$this->isGranted(MemberVoter::VIEW, $member)) {
            return [null, $this->forbidden()];
        }

        return [$member, null];
    }

    /**
     * Follow-up feature (editable/manual Member ID mode): memberId
     * handling is now gym-policy-dependent —
     *   - AUTO: a `memberId` key anywhere in the payload is rejected
     *     outright (422), independent of its value — system-generated,
     *     effectively immutable (see MemberProfile's docblock for why
     *     that's enforced here and not on the entity).
     *   - MANUAL: required on create (400 if missing/empty — front-desk
     *     must enter the gym's own number), optional on update (omit to
     *     leave unchanged), never nullable (can't be cleared to empty,
     *     only replaced), uniqueness pre-checked within the gym (409 if
     *     another member already has it — $excludeSelf skips the
     *     member's own current row on update, since re-submitting an
     *     unchanged value isn't a real collision).
     *
     * @return array{0: array{dob?: ?\DateTimeImmutable, gender?: ?Gender, addressLine?: ?string, addressCity?: ?string, addressPostalCode?: ?string, memberId?: string}, 1: ?JsonResponse}
     */
    private function parseProfileFields(array $data, Gym $gym, ?MemberProfile $excludeSelf, bool $isCreate): array
    {
        $fields = [];

        if ($gym->getMemberIdMode() === MemberIdMode::AUTO) {
            if (array_key_exists('memberId', $data)) {
                return [[], $this->memberIdRejected()];
            }
        } else {
            $hasMemberId = array_key_exists('memberId', $data);
            if (!$hasMemberId && $isCreate) {
                return [[], new JsonResponse([
                    'error' => 'invalid_request',
                    'message' => 'memberId is required — this gym assigns Member IDs manually.',
                ], 400)];
            }

            if ($hasMemberId) {
                $memberId = trim((string) $data['memberId']);
                if ($memberId === '') {
                    return [[], new JsonResponse(['error' => 'invalid_request', 'message' => 'memberId cannot be empty.'], 400)];
                }

                $existing = $this->memberProfiles->findOneByGymAndMemberId($gym, $memberId);
                if ($existing !== null && $existing !== $excludeSelf) {
                    return [[], new JsonResponse(['error' => 'conflict', 'message' => 'That Member ID is already in use.'], 409)];
                }

                $fields['memberId'] = $memberId;
            }
        }

        if (array_key_exists('dob', $data)) {
            if ($data['dob'] === null || $data['dob'] === '') {
                $fields['dob'] = null;
            } else {
                $dob = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['dob']);
                if ($dob === false) {
                    return [[], new JsonResponse(['error' => 'invalid_request', 'message' => 'dob must be YYYY-MM-DD.'], 400)];
                }
                $fields['dob'] = $dob;
            }
        }

        if (array_key_exists('gender', $data)) {
            if ($data['gender'] === null || $data['gender'] === '') {
                $fields['gender'] = null;
            } else {
                $gender = Gender::tryFrom((string) $data['gender']);
                if ($gender === null) {
                    return [[], new JsonResponse([
                        'error' => 'invalid_request',
                        'message' => 'gender must be one of: ' . implode(', ', array_map(fn ($c) => $c->value, Gender::cases())) . '.',
                    ], 400)];
                }
                $fields['gender'] = $gender;
            }
        }

        foreach (['addressLine', 'addressCity', 'addressPostalCode'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key] === null ? null : trim((string) $data[$key]);
                $fields[$key] = $value === '' ? null : $value;
            }
        }

        return [$fields, null];
    }

    private function memberIdRejected(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_request',
            'message' => 'memberId is system-generated for this gym and cannot be set or changed.',
        ], 422);
    }

    private function serializeMemberProfileDetail(MemberProfile $profile): array
    {
        $user = $profile->getUser();
        $membership = $this->memberships->getMembershipForMember($profile);

        return [
            'id' => (string) $user->getId(),
            'memberId' => $profile->getMemberId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'status' => $user->getStatus()->value,
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'membership' => $membership !== null ? $this->serializeMembership($membership) : null,
            'dob' => $profile->getDateOfBirth()?->format('Y-m-d'),
            'age' => $profile->getAge(),
            'gender' => $profile->getGender()?->value,
            'addressLine' => $profile->getAddressLine(),
            'addressCity' => $profile->getAddressCity(),
            'addressPostalCode' => $profile->getAddressPostalCode(),
        ];
    }

    private function serializePtSession(PtSession $session): array
    {
        return [
            'id' => (string) $session->getId(),
            'coachName' => $session->getCoach()->getUser()->getName(),
            'scheduledAt' => $session->getScheduledAt()->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $session->getDurationMinutes(),
            'status' => $session->getStatus()->value,
        ];
    }

    private function serializeWorkoutAssignment(WorkoutAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->getId(),
            'scheduleName' => $assignment->getSchedule()->getName(),
            'coachName' => $assignment->getCoach()->getName(),
            'status' => $assignment->getStatus(),
            'startDate' => $assignment->getStartDate()->format(\DateTimeInterface::ATOM),
            'assignedAt' => $assignment->getAssignedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeAttendanceLog(AttendanceLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
            'checkIn' => $log->getCheckIn()->format(\DateTimeInterface::ATOM),
            'checkOut' => $log->getCheckOut()?->format(\DateTimeInterface::ATOM),
            'branchName' => $log->getBranch()->getName(),
            'method' => $log->getMethod()->value,
        ];
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
            'memberId' => $profile->getMemberId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'role' => 'member',
            'status' => $user->getStatus()->value,
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'membership' => $membership !== null ? $this->serializeMembership($membership) : null,
            // roadmap Phase 16 hub model: a Member isn't restricted to a
            // branch, but the Owner's roster filter still needs *some*
            // branch to filter by — their enrolling branch (the one their
            // active plan belongs to) is the only one that means anything
            // here, same source MemberVoter's Staff branch already uses.
            'branchIds' => $membership !== null ? [(string) $membership->getPlan()->getBranch()->getId()] : [],
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
            // Unlike a Member's single enrolling branch, a Coach can be
            // assigned to more than one — the roster filter matches on
            // any of them.
            'branchIds' => array_map(
                fn ($assignment) => (string) $assignment->getBranch()->getId(),
                $user->getBranchAssignments()->toArray(),
            ),
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

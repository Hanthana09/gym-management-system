<?php

namespace App\Coach;

use App\Audit\AuditLogger;
use App\Entity\CoachProfile;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * gym-management-coach-management.md: coach CRUD for an Owner —
 * create (immediately active), edit identity + profile fields, and
 * suspend / reactivate. Mirrors MemberService / MemberCreationService's
 * structure (audit-logged, idempotent status change, PATCH semantics on
 * update) — CoachController does all the validation and hands this a
 * pre-checked field array.
 *
 * Direct creation deliberately overrides architecture doc §6.7's
 * invite-only rule for coaches (and CLAUDE.md's named anti-pattern) —
 * confirmed with the product owner for this feature; both docs carry an
 * updated note. Same shape as MemberCreationService::createWalkIn():
 * no invitation, no OTP, no pending-approval step — the account is
 * ACTIVE on creation. Branch assignment stays a separate Owner action
 * (POST /branches/:id/assign), unchanged.
 */
class CoachService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param array{specialty?: ?string, bio?: ?string, hourlyRate?: ?string} $profileFields
     */
    public function createCoach(
        Gym $gym,
        string $name,
        ?string $email,
        ?string $phone,
        array $profileFields,
        User $actingOwner,
    ): CoachProfile {
        $user = new User($name, $email, $phone, UserRole::COACH, UserStatus::ACTIVE);
        $this->em->persist($user);

        $profile = new CoachProfile($user);
        $this->applyProfileFields($profile, $profileFields);
        $this->em->persist($profile);

        $this->em->flush();

        $this->auditLogger->log($actingOwner, 'coach.created', 'User', $user->getId(), [
            'gymId' => (string) $gym->getId(),
        ]);

        return $profile;
    }

    /**
     * PATCH semantics: only keys the caller validated as present are in
     * $fields. A present key with value null clears the column (for the
     * nullable profile fields); name is never null (validated non-empty
     * upstream), email/phone may be nulled to drop a contact method as
     * long as at least one remains (also enforced upstream).
     *
     * @param array{name?: string, email?: ?string, phone?: ?string, specialty?: ?string, bio?: ?string, hourlyRate?: ?string} $fields
     */
    public function updateProfile(CoachProfile $coach, array $fields, User $actingUser): void
    {
        if ($fields === []) {
            return;
        }

        $user = $coach->getUser();

        if (array_key_exists('name', $fields)) {
            $user->setName($fields['name']);
        }
        if (array_key_exists('email', $fields)) {
            $user->setEmail($fields['email']);
        }
        if (array_key_exists('phone', $fields)) {
            $user->setPhone($fields['phone']);
        }

        $this->applyProfileFields($coach, $fields);

        $this->em->flush();

        $this->auditLogger->log($actingUser, 'coach.profile_updated', 'User', $user->getId(), [
            'fields' => array_keys($fields),
        ]);
    }

    /**
     * Idempotent, same contract as MemberService::updateStatus() —
     * setting the status to what it already is is a no-op with no audit
     * entry, so re-clicking "Suspend" doesn't spam the log.
     */
    public function updateStatus(CoachProfile $coach, UserStatus $newStatus, User $actingOwner): void
    {
        $user = $coach->getUser();
        $previousStatus = $user->getStatus();
        if ($previousStatus === $newStatus) {
            return;
        }

        $user->setStatus($newStatus);
        $this->em->flush();

        $this->auditLogger->log($actingOwner, 'coach.status_changed', 'User', $user->getId(), [
            'previousStatus' => $previousStatus->value,
            'newStatus' => $newStatus->value,
        ]);
    }

    /** @param array{specialty?: ?string, bio?: ?string, hourlyRate?: ?string} $fields */
    private function applyProfileFields(CoachProfile $coach, array $fields): void
    {
        if (array_key_exists('specialty', $fields)) {
            $coach->setSpecialty($fields['specialty']);
        }
        if (array_key_exists('bio', $fields)) {
            $coach->setBio($fields['bio']);
        }
        if (array_key_exists('hourlyRate', $fields)) {
            $coach->setHourlyRate($fields['hourlyRate']);
        }
    }
}

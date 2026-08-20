<?php

namespace App\Member;

use App\Audit\AuditLogger;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\Gender;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * gym-management-member-profile-extension.md §4: the manual "walk-in"
 * pathway alongside the existing invite/approve flow (InvitationService,
 * untouched). Overrides FR §15.2's "member creation only ever happens
 * through the invite/approve flow" and CLAUDE.md's named anti-pattern —
 * confirmed explicitly with the product owner for this phase; both docs
 * were updated alongside this change. Deliberately narrower than
 * InvitationService: no OTP, no pending-approval step, no email/SMS
 * round trip — the account is created ACTIVE immediately, which is the
 * entire point of a front-desk walk-in flow. Uses the same `User`/
 * `MemberProfile` construction primitives InvitationService::approve()/
 * provisionUserForDestination() already use, just synchronously and
 * Owner/Staff-triggered instead of invitee-triggered.
 *
 * Follow-up feature (editable/manual Member ID mode): `$gym` is now
 * supplied by the controller rather than resolved here — the controller
 * already has to resolve it first (Owner vs Staff resolve differently,
 * and it needs the gym's memberIdMode to validate the payload before
 * this is even called), so resolving it a second time here would just
 * be duplicated logic with two sources of truth.
 */
class MemberCreationService
{
    public function __construct(
        private readonly MemberIdGenerator $memberIds,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{dob?: ?\DateTimeImmutable, gender?: ?Gender, addressLine?: ?string, addressCity?: ?string, addressPostalCode?: ?string, memberId?: string} $profileFields
     */
    public function createWalkIn(
        Gym $gym,
        string $name,
        ?string $email,
        ?string $phone,
        User $actingUser,
        array $profileFields,
    ): MemberProfile {
        $user = new User($name, $email, $phone, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist($user);

        $profile = new MemberProfile($user);
        $this->em->persist($profile);

        $manualMemberId = $profileFields['memberId'] ?? null;
        unset($profileFields['memberId']);
        $this->applyProfileFields($profile, $profileFields);

        $this->em->flush();

        if ($manualMemberId !== null) {
            // Manual mode: the controller already validated uniqueness —
            // assign directly rather than going through the auto-sequence
            // generator, which this gym's mode never touches.
            $profile->assignGym($gym);
            $profile->setMemberId($manualMemberId);
            $this->em->flush();
        } else {
            $this->memberIds->generateFor($profile, $gym);
        }

        $this->auditLogger->log($actingUser, 'member.created_manual', 'User', $user->getId(), [
            'gymId' => (string) $gym->getId(),
        ]);

        return $profile;
    }

    /** @param array{dob?: ?\DateTimeImmutable, gender?: ?Gender, addressLine?: ?string, addressCity?: ?string, addressPostalCode?: ?string} $fields */
    private function applyProfileFields(MemberProfile $profile, array $fields): void
    {
        if (array_key_exists('dob', $fields)) {
            $profile->setDateOfBirth($fields['dob']);
        }
        if (array_key_exists('gender', $fields)) {
            $profile->setGender($fields['gender']);
        }
        if (array_key_exists('addressLine', $fields)) {
            $profile->setAddressLine($fields['addressLine']);
        }
        if (array_key_exists('addressCity', $fields)) {
            $profile->setAddressCity($fields['addressCity']);
        }
        if (array_key_exists('addressPostalCode', $fields)) {
            $profile->setAddressPostalCode($fields['addressPostalCode']);
        }
    }
}

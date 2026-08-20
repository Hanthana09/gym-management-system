<?php

namespace App\Member;

use App\Audit\AuditLogger;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\Gender;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * architecture doc §7: PATCH /members/:id/status (Owner — suspend/
 * remove; not initial add, see /invitations). There's no "removed"
 * status distinct from "suspended" in this data model (UserStatus is
 * pending_approval|active|suspended) — Membership/Invoice/AttendanceLog
 * history all reference the member, so "remove" is a status transition,
 * not a row deletion. Suspending already has real effect:
 * AttendanceService::checkIn() (Phase 5) blocks a suspended account with
 * a specific reason, so this alone is enough to functionally "remove"
 * someone from the active roster without touching their history.
 */
class MemberService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * architecture doc §9: suspension is explicitly named as an action
     * requiring an audit log entry, same tier as plan changes and
     * invitations sent. Idempotent — setting the status to what it
     * already is is a no-op with no audit entry, so re-clicking "Suspend"
     * on an already-suspended member doesn't spam the log.
     */
    public function updateStatus(MemberProfile $member, UserStatus $newStatus, User $actingOwner): void
    {
        $user = $member->getUser();
        $previousStatus = $user->getStatus();
        if ($previousStatus === $newStatus) {
            return;
        }

        $user->setStatus($newStatus);
        $this->em->flush();

        $this->auditLogger->log($actingOwner, 'member.status_changed', 'User', $user->getId(), [
            'previousStatus' => $previousStatus->value,
            'newStatus' => $newStatus->value,
        ]);
    }

    /**
     * gym-management-member-profile-extension.md §4: dob/gender/address*,
     * plus `memberId` for gyms in Gym::memberIdMode MANUAL (follow-up
     * feature) — the controller has already decided whether `memberId`
     * is allowed in this payload at all (rejected outright for AUTO-mode
     * gyms) and pre-checked its uniqueness before calling this, so this
     * method just applies whatever the caller validated. $fields only
     * contains keys the caller actually validated as present in the
     * incoming payload (PATCH semantics: an omitted key leaves the
     * existing value untouched, a present key with value null clears it
     * — except memberId, which the controller never allows to be
     * cleared to null, only replaced with another non-empty value).
     *
     * @param array{dob?: ?\DateTimeImmutable, gender?: ?Gender, addressLine?: ?string, addressCity?: ?string, addressPostalCode?: ?string, memberId?: string} $fields
     */
    public function updateProfile(MemberProfile $member, array $fields, User $actingUser): void
    {
        if ($fields === []) {
            return;
        }

        if (array_key_exists('dob', $fields)) {
            $member->setDateOfBirth($fields['dob']);
        }
        if (array_key_exists('gender', $fields)) {
            $member->setGender($fields['gender']);
        }
        if (array_key_exists('addressLine', $fields)) {
            $member->setAddressLine($fields['addressLine']);
        }
        if (array_key_exists('addressCity', $fields)) {
            $member->setAddressCity($fields['addressCity']);
        }
        if (array_key_exists('addressPostalCode', $fields)) {
            $member->setAddressPostalCode($fields['addressPostalCode']);
        }
        if (array_key_exists('memberId', $fields)) {
            $member->setMemberId($fields['memberId']);
        }

        $this->em->flush();

        $this->auditLogger->log($actingUser, 'member.profile_updated', 'User', $member->getUser()->getId(), [
            'fields' => array_keys($fields),
        ]);
    }
}

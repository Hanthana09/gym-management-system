<?php

namespace App\Member;

use App\Audit\AuditLogger;
use App\Entity\MemberProfile;
use App\Entity\User;
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
}

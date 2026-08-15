<?php

namespace App\Branch;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\AttendanceLogRepository;
use App\Repository\BranchAssignmentRepository;
use App\Repository\MembershipPlanRepository;
use App\Repository\PtSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * architecture doc §6.12: branch CRUD, Coach/Staff assignment. Never
 * touches Member — Members are hub-scoped, not branch-assigned
 * (architecture doc §5.2's core decision for this phase); assign()/
 * unassign() reject anything that isn't a Coach or Staff user outright.
 */
class BranchService
{
    public function __construct(
        private readonly BranchAssignmentRepository $assignments,
        private readonly AttendanceLogRepository $attendanceLogs,
        private readonly MembershipPlanRepository $membershipPlans,
        private readonly PtSessionRepository $ptSessions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function create(Gym $gym, string $name, string $address, ?string $phone): Branch
    {
        $branch = new Branch($gym, $name, $address, $phone);
        $this->em->persist($branch);
        $this->em->flush();

        return $branch;
    }

    public function update(Branch $branch, ?string $name, ?string $address, ?string $phone): void
    {
        if ($name !== null) {
            $branch->setName($name);
        }
        if ($address !== null) {
            $branch->setAddress($address);
        }
        if ($phone !== null) {
            $branch->setPhone($phone);
        }
        $this->em->flush();
    }

    /** functional requirements §14.1: stops new check-ins/bookings, historical data stays intact — nothing here touches existing rows. */
    public function deactivate(Branch $branch): void
    {
        $branch->deactivate();
        $this->em->flush();
    }

    public function activate(Branch $branch): void
    {
        $branch->activate();
        $this->em->flush();
    }

    /**
     * Branch delete facility: a genuine hard delete, but only ever for a
     * branch that's never actually been used — the primary branch (every
     * gym must always have exactly one, roadmap Phase 16.1's Definition
     * of Done) and any branch with attendance/plan/PT-session history are
     * both rejected outright. Deactivate (above) is still the tool for
     * removing a branch that HAS been used — functional requirements
     * §14.1 is explicit that deactivating must leave historical data
     * intact and reportable, which a hard delete cascading through
     * NOT NULL branch_id foreign keys could never do.
     *
     * Existing BranchAssignment rows ARE removed as part of this — unlike
     * attendance/plans/sessions, an assignment isn't a historical fact
     * worth preserving, just current Coach/Staff placement, so requiring
     * the Owner to manually unassign everyone first would be pure
     * friction with no data-integrity benefit.
     */
    public function delete(Branch $branch): void
    {
        if ($branch->isPrimary()) {
            throw new BranchDeletionConflictException('primary_branch', 'The primary branch cannot be deleted.');
        }

        if ($this->attendanceLogs->existsForBranch($branch)
            || $this->membershipPlans->existsForBranch($branch)
            || $this->ptSessions->existsForBranch($branch)
        ) {
            throw new BranchDeletionConflictException(
                'branch_in_use',
                'This branch has attendance, plans, or PT sessions recorded against it and cannot be deleted. Deactivate it instead.',
            );
        }

        foreach ($this->assignments->findByBranch($branch) as $assignment) {
            $this->em->remove($assignment);
        }
        $this->em->remove($branch);
        $this->em->flush();
    }

    /**
     * functional requirements §14.2: assigning is how a Coach/Staff's
     * scoped access is granted in the first place — Member is rejected
     * outright, not silently ignored, since a Member assignment would be
     * a real bug (architecture doc §5.2), not a valid no-op.
     */
    public function assign(Branch $branch, User $user): BranchAssignment
    {
        if ($user->getRole() !== UserRole::COACH && $user->getRole() !== UserRole::STAFF) {
            throw new BranchAssignmentConflictException('invalid_role', 'Only a Coach or Staff account can be assigned to a branch.');
        }

        if ($this->assignments->findOneForUserAndBranch($user, $branch) !== null) {
            throw new BranchAssignmentConflictException('already_assigned', 'This user is already assigned to this branch.');
        }

        $assignment = new BranchAssignment($user, $branch);
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    /**
     * functional requirements §14.2: "immediately lose view/action access
     * ... enforced at the API level, not just hidden from the UI" — true
     * by construction here, since hasAssignedBranch() re-queries this same
     * table on every request; there's no cached/stale grant to worry about.
     */
    public function unassign(Branch $branch, User $user): void
    {
        $assignment = $this->assignments->findOneForUserAndBranch($user, $branch);
        if ($assignment === null) {
            throw new BranchAssignmentConflictException('not_assigned', 'This user is not assigned to this branch.');
        }

        $user->removeBranchAssignment($assignment);
        $this->em->remove($assignment);
        $this->em->flush();
    }
}

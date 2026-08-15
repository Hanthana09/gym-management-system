<?php

namespace App\Branch;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\BranchAssignmentRepository;
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

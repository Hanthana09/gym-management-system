<?php

namespace App\Entity;

use App\Repository\BranchAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * roadmap Phase 16 / architecture doc §5.1, §6.12: Coach/Staff ↔ Branch,
 * many-to-many via this join entity. Never created for a Member (Members
 * are hub-scoped, not branch-assigned — architecture doc §5.2's core
 * decision) or an Owner (implicitly has every branch). The constructor
 * keeps `User::branchAssignments` in sync in memory (no EntityManager
 * needed), the same bidirectional-relation pattern used wherever else
 * this codebase needs a Voter to traverse a collection without DI.
 */
#[ORM\Entity(repositoryClass: BranchAssignmentRepository::class)]
#[ORM\UniqueConstraint(name: 'user_branch_unique', columns: ['user_id', 'branch_id'])]
class BranchAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'branchAssignments')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: false)]
    private Branch $branch;

    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;

    public function __construct(User $user, Branch $branch)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->branch = $branch;
        $this->assignedAt = new \DateTimeImmutable();
        $user->addBranchAssignment($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }
}

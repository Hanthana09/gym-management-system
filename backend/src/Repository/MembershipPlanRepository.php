<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\MembershipPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembershipPlan>
 */
class MembershipPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembershipPlan::class);
    }

    /** roadmap Phase 16: plans are now branch-scoped — this replaces the old findByGym(). */
    public function findByBranch(Branch $branch): array
    {
        return $this->findBy(['branch' => $branch], ['name' => 'ASC']);
    }

    /** Branch delete facility: a branch with any plan (past or present) ever created against it can't be hard-deleted — those plans, and any membership/invoice history through them, must stay intact. */
    public function existsForBranch(Branch $branch): bool
    {
        return $this->count(['branch' => $branch]) > 0;
    }
}

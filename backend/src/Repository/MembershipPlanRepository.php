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
}

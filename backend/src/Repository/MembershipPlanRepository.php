<?php

namespace App\Repository;

use App\Entity\Gym;
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

    /** @return MembershipPlan[] */
    public function findByGym(Gym $gym): array
    {
        return $this->findBy(['gym' => $gym], ['name' => 'ASC']);
    }
}

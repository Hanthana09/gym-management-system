<?php

namespace App\Repository;

use App\Entity\ReferralLead;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferralLead>
 */
class ReferralLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralLead::class);
    }

    /** Own submitted leads — newest first (Coach and Owner dashboards both use this). */
    public function findForReferrer(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.referredBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

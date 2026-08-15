<?php

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BranchAssignment>
 */
class BranchAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BranchAssignment::class);
    }

    public function findOneForUserAndBranch(User $user, Branch $branch): ?BranchAssignment
    {
        return $this->findOneBy(['user' => $user, 'branch' => $branch]);
    }

    /** @return BranchAssignment[] */
    public function findByBranch(Branch $branch): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.user', 'u')
            ->addSelect('u')
            ->andWhere('a.branch = :branch')
            ->setParameter('branch', $branch)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

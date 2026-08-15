<?php

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneByPhone(string $phone): ?User
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    /**
     * roadmap Phase 16: source for the Owner's branch-assignment picker —
     * every active Coach or Staff account, the only two roles
     * BranchService::assign() ever accepts.
     *
     * @param UserRole[] $roles
     * @return User[]
     */
    public function findActiveByRoles(array $roles): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.role IN (:roles)')
            ->andWhere('u.status = :active')
            ->setParameter('roles', $roles)
            ->setParameter('active', UserStatus::ACTIVE)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

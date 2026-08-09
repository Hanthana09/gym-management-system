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

    /** Roadmap Phase 6: the Member's coach picker — active coaches only. */
    public function findActiveByRole(UserRole $role): array
    {
        return $this->findBy(['role' => $role, 'status' => UserStatus::ACTIVE], ['name' => 'ASC']);
    }

    /** Roadmap Phase 7: gym-wide announcement fan-out — every active Coach/Member. */
    public function findActiveByRoles(array $roles): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.role IN (:roles)')
            ->andWhere('u.status = :active')
            ->setParameter('roles', $roles)
            ->setParameter('active', UserStatus::ACTIVE)
            ->getQuery()
            ->getResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\CoachProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachProfile>
 */
class CoachProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachProfile::class);
    }

    public function findOneByUser(User $user): ?CoachProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * The Member's coach picker (roadmap Phase 6) must only ever offer
     * coaches who actually have a CoachProfile — a coach User row without
     * one (e.g. seeded directly into the database, bypassing the
     * invite/approve flow that's the only normal path to creating one;
     * see architecture doc §6.7) would otherwise show up as pickable and
     * then 404 on every subsequent action against them (booking, viewing
     * their schedule). Joining on CoachProfile itself, rather than
     * filtering User by role, is what actually enforces that.
     *
     * @return CoachProfile[]
     */
    public function findAllWithActiveUser(): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->andWhere('u.status = :active')
            ->setParameter('active', UserStatus::ACTIVE)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Owner's roster page (MemberController::list(), extended to include
     * coaches). Unlike findAllWithActiveUser() above — which must exclude
     * suspended coaches because it feeds the Member's booking picker — an
     * Owner managing accounts needs to see suspended coaches too, so this
     * one carries no status filter.
     *
     * @return CoachProfile[]
     */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->addSelect('u')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

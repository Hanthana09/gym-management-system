<?php

namespace App\Repository;

use App\Entity\CoachProfile;
use App\Entity\User;
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
}

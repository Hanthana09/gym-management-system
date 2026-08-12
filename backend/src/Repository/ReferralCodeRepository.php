<?php

namespace App\Repository;

use App\Entity\ReferralCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferralCode>
 */
class ReferralCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralCode::class);
    }

    public function findOneByOwner(User $owner): ?ReferralCode
    {
        return $this->findOneBy(['owner' => $owner]);
    }

    public function findOneByCode(string $code): ?ReferralCode
    {
        return $this->findOneBy(['code' => $code]);
    }
}

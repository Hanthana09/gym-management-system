<?php

namespace App\Repository;

use App\Entity\OtpCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OtpCode>
 */
class OtpCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OtpCode::class);
    }

    /** Most recent, still-relevant code for a destination (verify checks this one). */
    public function findLatestForDestination(string $destination): ?OtpCode
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.destination = :destination')
            ->setParameter('destination', $destination)
            ->orderBy('o.expiresAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

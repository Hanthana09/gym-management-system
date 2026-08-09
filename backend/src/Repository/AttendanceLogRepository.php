<?php

namespace App\Repository;

use App\Entity\AttendanceLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttendanceLog>
 *
 * architecture doc §5.1's ATTENDANCE_LOG has no gym_id column, and this is
 * a single-gym product (CLAUDE.md) — every query here is effectively
 * "the gym's" attendance without needing a join through
 * member → membership → plan → gym just to scope by a single tenant.
 */
class AttendanceLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceLog::class);
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.checkIn >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return AttendanceLog[] */
    public function findByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.checkIn >= :from')
            ->andWhere('a.checkIn < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.checkIn', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

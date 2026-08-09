<?php

namespace App\Repository;

use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Enum\MembershipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /** functional requirements §3.1: a plan with an active/paused membership can't be silently deleted. */
    public function hasOngoingMembership(MembershipPlan $plan): bool
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.plan = :plan')
            ->andWhere('m.status IN (:ongoing)')
            ->setParameter('plan', $plan)
            ->setParameter('ongoing', [MembershipStatus::ACTIVE, MembershipStatus::PAUSED])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findOneOngoingForMember(MemberProfile $member): ?Membership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.member = :member')
            ->andWhere('m.status IN (:ongoing)')
            ->setParameter('member', $member)
            ->setParameter('ongoing', [MembershipStatus::ACTIVE, MembershipStatus::PAUSED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Most recent membership regardless of status — "My membership" still shows an expired/cancelled one rather than nothing. */
    public function findMostRecentForMember(MemberProfile $member): ?Membership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.member = :member')
            ->setParameter('member', $member)
            ->orderBy('m.startDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** architecture doc §8.3: memberships crossing the 7/3/1-day-before-expiry threshold today. */
    public function findActiveExpiringInDays(int $days): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :active')
            ->andWhere('m.endDate = :target')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->setParameter('target', new \DateTimeImmutable("+{$days} days"))
            ->getQuery()
            ->getResult();
    }

    /** Active memberships whose end date has already passed — due to transition to expired. */
    public function findActivePastEndDate(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :active')
            ->andWhere('m.endDate < :today')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()
            ->getResult();
    }
}

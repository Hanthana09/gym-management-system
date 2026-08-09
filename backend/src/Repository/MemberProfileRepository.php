<?php

namespace App\Repository;

use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\PtSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberProfile>
 */
class MemberProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberProfile::class);
    }

    public function findOneByUser(User $user): ?MemberProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Roadmap Phase 7: defines "own clients" for a Coach's announcement
     * audience (functional requirements §6.3) — any Member with at least
     * one PT session (any status) booked with this Coach. This is a
     * narrower, purpose-built definition for the announcement feature, not
     * a change to MemberProfile::hasCoach() (still a Phase-6-onward
     * placeholder for a different, still-undefined "assigned coach"
     * concept used by AttendanceVoter) — this method doesn't touch that.
     *
     * @return MemberProfile[]
     */
    public function findClientsOfCoach(CoachProfile $coach): array
    {
        $memberUserIds = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(s.member)')
            ->from(PtSession::class, 's')
            ->andWhere('s.coach = :coach')
            ->setParameter('coach', $coach)
            ->getQuery()
            ->getSingleColumnResult();

        if ($memberUserIds === []) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->andWhere('m.user IN (:ids)')
            ->setParameter('ids', $memberUserIds)
            ->getQuery()
            ->getResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\CoachProfile;
use App\Entity\Gym;
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
     * Owner's member roster (architecture doc §7: GET /members). Sourced
     * from MemberProfile, not a role-filtered User query — a member-role
     * User only gets a MemberProfile once their invitation is approved
     * (architecture doc §6.7), so this naturally excludes still-pending
     * invitees. They already have their own visibility via the Owner's
     * Invitations panel (Phase 3); this list is the actual onboarded
     * roster, a different concern.
     *
     * @return MemberProfile[]
     */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.user', 'u')
            ->addSelect('u')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
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

    /** Manual Member ID mode's uniqueness pre-check — a clean validation error instead of a caught unique-constraint exception. */
    public function findOneByGymAndMemberId(Gym $gym, string $memberId): ?MemberProfile
    {
        return $this->findOneBy(['gym' => $gym, 'memberId' => $memberId]);
    }

    /** Gates the "can't change Member ID mode once members exist" rule (GymMemberIdSettingsController). */
    public function existsForGym(Gym $gym): bool
    {
        return $this->createQueryBuilder('m')
            ->select('1')
            ->andWhere('m.gym = :gym')
            ->setParameter('gym', $gym)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}

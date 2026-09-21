<?php

namespace App\Repository;

use App\Entity\Gym;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\InvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /** Still-pending invitation for this gym + destination (functional requirements §2.1 duplicate handling). */
    public function findPendingForDestination(Gym $gym, string $destination): ?Invitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.gym = :gym')
            ->andWhere('i.status = :pending')
            ->andWhere('i.email = :destination OR i.phone = :destination')
            ->setParameter('gym', $gym)
            ->setParameter('pending', \App\Enum\InvitationStatus::PENDING)
            ->setParameter('destination', $destination)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Gym-agnostic lookup used by OTP first-time login (destination alone identifies the invitee). */
    public function findAnyPendingForDestination(string $destination): ?Invitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.status = :pending')
            ->andWhere('i.email = :destination OR i.phone = :destination')
            ->setParameter('pending', \App\Enum\InvitationStatus::PENDING)
            ->setParameter('destination', $destination)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Owner-facing "everyone I've invited" listing (functional requirements
     * §2.1: "the invitation shows as 'pending' in my invitations list") —
     * newest first, optionally narrowed to one status.
     *
     * @return Invitation[]
     */
    public function findForGym(Gym $gym, ?InvitationStatus $status = null): array
    {
        // Secondary sort on id: createdAt's column precision can't tell
        // apart two rows created in the same request (e.g. a bulk import's
        // 100+ rows), but id is a time-ordered UUIDv7, so it still sorts
        // them in creation order.
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.gym = :gym')
            ->setParameter('gym', $gym)
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        if ($status !== null) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** Every invitation addressed to this user, by account link or by matching email/phone (mirrors InvitationVoter::RESPOND). */
    public function findForUser(User $user): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.user = :user OR i.email = :email OR i.phone = :phone')
            ->setParameter('user', $user)
            ->setParameter('email', $user->getEmail())
            ->setParameter('phone', $user->getPhone())
            ->orderBy('i.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * gym-management-member-profile-extension.md: the memberId backfill
     * command's source of truth for "which gym did this pre-existing
     * member actually join" — same reasoning as
     * findApprovedUsersForGym()'s docblock below, applied in the other
     * direction (user -&gt; gym instead of gym -&gt; users).
     */
    public function findApprovedForUser(User $user): ?Invitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.user = :user')
            ->andWhere('i.status = :approved')
            ->setParameter('user', $user)
            ->setParameter('approved', \App\Enum\InvitationStatus::APPROVED)
            ->orderBy('i.respondedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Roadmap Phase 7: "gym-wide" announcement recipients. User has no
     * direct gym_id (single-gym product, architecture doc §5.1) — an
     * approved Invitation.gym is the only place a Coach/Member is ever
     * linked to a specific gym, so this is the sole correct way to scope
     * "everyone at my gym" once more than one gym exists in the data.
     *
     * @return User[]
     */
    public function findApprovedUsersForGym(Gym $gym): array
    {
        $userIds = $this->createQueryBuilder('i')
            ->select('DISTINCT IDENTITY(i.user)')
            ->andWhere('i.gym = :gym')
            ->andWhere('i.status = :approved')
            ->andWhere('i.user IS NOT NULL')
            ->setParameter('gym', $gym)
            ->setParameter('approved', \App\Enum\InvitationStatus::APPROVED)
            ->getQuery()
            ->getSingleColumnResult();

        if ($userIds === []) {
            return [];
        }

        return $this->getEntityManager()->getRepository(User::class)->findBy(['id' => $userIds]);
    }
}

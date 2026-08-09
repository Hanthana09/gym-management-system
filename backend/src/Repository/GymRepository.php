<?php

namespace App\Repository;

use App\Entity\Gym;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Gym>
 */
class GymRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Gym::class);
    }

    public function findOneByOwner(User $owner): ?Gym
    {
        return $this->findOneBy(['owner' => $owner]);
    }

    /**
     * Single-gym product (CLAUDE.md) — a Coach posting an announcement
     * needs "the gym" without a direct gym_id on User (see AttendanceLogRepository
     * for the same single-tenant reasoning). Returns null only if no Owner
     * has ever provisioned a gym yet, which can't happen for an active
     * Coach (they were necessarily invited by an Owner, and sending an
     * invitation already lazily provisions the gym).
     */
    public function findTheOnlyGym(): ?Gym
    {
        return $this->findOneBy([]);
    }
}

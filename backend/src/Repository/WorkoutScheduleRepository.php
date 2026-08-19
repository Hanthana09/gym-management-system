<?php

namespace App\Repository;

use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkoutSchedule>
 */
class WorkoutScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkoutSchedule::class);
    }

    /** Coach's own template list, and the assign flow's schedule picker. @return WorkoutSchedule[] */
    public function findByCoach(User $coach): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('s.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function belongsToGym(WorkoutSchedule $schedule, Gym $gym): bool
    {
        return $schedule->getGym() === $gym;
    }
}

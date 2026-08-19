<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Entity\WorkoutSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkoutAssignment>
 */
class WorkoutAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkoutAssignment::class);
    }

    /** WorkoutAssignmentService::assign() §4 step 2 — the row a new assignment for this pair would replace. */
    public function findActiveForCoachAndMember(User $coach, User $member): ?WorkoutAssignment
    {
        return $this->findOneBy(['coach' => $coach, 'member' => $member, 'status' => WorkoutAssignment::STATUS_ACTIVE]);
    }

    /** GET /workout-assignments?member=me&status=active. @return WorkoutAssignment[] */
    public function findByMemberAndStatus(User $member, ?string $status): array
    {
        $criteria = ['member' => $member];
        if ($status !== null) {
            $criteria['status'] = $status;
        }

        return $this->findBy($criteria, ['assignedAt' => 'DESC']);
    }

    /**
     * WorkoutScheduleMercurePublisher §6: every active assignment referencing
     * a schedule, to fan out a thin "refetch" event to each member.
     *
     * @return WorkoutAssignment[]
     */
    public function findActiveForSchedule(WorkoutSchedule $schedule): array
    {
        return $this->findBy(['schedule' => $schedule, 'status' => WorkoutAssignment::STATUS_ACTIVE]);
    }
}

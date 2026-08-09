<?php

namespace App\PersonalTracking;

use App\Entity\BodyMetric;
use App\Entity\MemberProfile;
use App\Entity\WorkoutLog;
use App\Repository\BodyMetricRepository;
use App\Repository\WorkoutLogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * architecture doc §6.5: Member-only CRUD on WORKOUT_LOG and BODY_METRIC.
 * No event dispatching here — nothing in functional requirements §7
 * or architecture doc §6.6 names a notification for personal tracking
 * activity, so this module stays self-contained (create + list only).
 */
class PersonalTrackingService
{
    public function __construct(
        private readonly WorkoutLogRepository $workoutLogs,
        private readonly BodyMetricRepository $bodyMetrics,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function logWorkout(MemberProfile $member, \DateTimeImmutable $date, string $type, int $durationMinutes, array $metrics): WorkoutLog
    {
        $log = new WorkoutLog($member, $date, $type, $durationMinutes, $metrics);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /** @return WorkoutLog[] */
    public function listWorkouts(MemberProfile $member): array
    {
        return $this->workoutLogs->findForMember($member);
    }

    public function recordBodyMetric(MemberProfile $member, \DateTimeImmutable $date, string $weightKg, ?string $bodyFatPct): BodyMetric
    {
        $metric = new BodyMetric($member, $date, $weightKg, $bodyFatPct);
        $this->em->persist($metric);
        $this->em->flush();

        return $metric;
    }

    /** @return BodyMetric[] */
    public function listBodyMetrics(MemberProfile $member): array
    {
        return $this->bodyMetrics->findForMember($member);
    }
}

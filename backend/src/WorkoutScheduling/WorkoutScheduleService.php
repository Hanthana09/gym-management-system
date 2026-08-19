<?php

namespace App\WorkoutScheduling;

use App\Entity\Exercise;
use App\Entity\Gym;
use App\Entity\User;
use App\Entity\WorkoutSchedule;
use App\Entity\WorkoutScheduleExercise;
use App\Event\WorkoutScheduleExerciseChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * setly-phase-workout-scheduling.md §2.1: "Schedule is a template ...
 * Editing the template edits it for every member currently assigned to
 * it." Every line-item write here dispatches
 * WorkoutScheduleExerciseChangedEvent so WorkoutScheduleMercurePublisher
 * (§6) can fan the change out to each active assignment's member —
 * exactly the "no manual sync step" requirement from the roadmap's
 * verification checklist.
 */
class WorkoutScheduleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function createSchedule(Gym $gym, User $coach, string $name, string $type): WorkoutSchedule
    {
        $schedule = new WorkoutSchedule($gym, $coach, $name, $type);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    public function updateSchedule(WorkoutSchedule $schedule, string $name, string $type, string $status): void
    {
        $schedule->update($name, $type, $status);
        $this->em->flush();
    }

    public function addExercise(
        WorkoutSchedule $schedule,
        Exercise $exercise,
        int $dayNumber,
        int $order,
        int $sets,
        int $reps,
        ?int $restSeconds,
        ?string $notes,
    ): WorkoutScheduleExercise {
        $line = new WorkoutScheduleExercise($schedule, $exercise, $dayNumber, $order, $sets, $reps, $restSeconds, $notes);
        $this->em->persist($line);
        $schedule->touch();
        $this->em->flush();

        $this->dispatchChanged($schedule, WorkoutScheduleExerciseChangedEvent::CHANGE_CREATED);

        return $line;
    }

    public function updateExercise(WorkoutScheduleExercise $line, int $dayNumber, int $order, int $sets, int $reps, ?int $restSeconds, ?string $notes): void
    {
        $line->update($dayNumber, $order, $sets, $reps, $restSeconds, $notes);
        $line->getSchedule()->touch();
        $this->em->flush();

        $this->dispatchChanged($line->getSchedule(), WorkoutScheduleExerciseChangedEvent::CHANGE_UPDATED);
    }

    public function removeExercise(WorkoutScheduleExercise $line): void
    {
        $schedule = $line->getSchedule();
        $this->em->remove($line);
        $schedule->touch();
        $this->em->flush();

        $this->dispatchChanged($schedule, WorkoutScheduleExerciseChangedEvent::CHANGE_DELETED);
    }

    private function dispatchChanged(WorkoutSchedule $schedule, string $changeType): void
    {
        $this->dispatcher->dispatch(
            new WorkoutScheduleExerciseChangedEvent($schedule, $changeType),
            WorkoutScheduleExerciseChangedEvent::NAME,
        );
    }
}

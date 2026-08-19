<?php

namespace App\EventListener;

use App\Entity\WorkoutAssignment;
use App\Event\WorkoutAssignmentCreatedEvent;
use App\Event\WorkoutAssignmentReplacedEvent;
use App\Event\WorkoutScheduleExerciseChangedEvent;
use App\Repository\WorkoutAssignmentRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * setly-phase-workout-scheduling.md §6: live-sync for members with the app
 * open. Non-private topics (no subscriber JWT required), thin payload
 * (`{assignmentId, scheduleId, changeType}` — client refetches, no full
 * object over the wire), same tradeoff as PtSessionMercurePublisher.
 */
class WorkoutScheduleMercurePublisher implements EventSubscriberInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly WorkoutAssignmentRepository $assignments,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkoutAssignmentCreatedEvent::NAME => 'onAssignmentCreated',
            WorkoutAssignmentReplacedEvent::NAME => 'onAssignmentReplaced',
            WorkoutScheduleExerciseChangedEvent::NAME => 'onScheduleExerciseChanged',
        ];
    }

    public function onAssignmentCreated(WorkoutAssignmentCreatedEvent $event): void
    {
        $assignment = $event->getAssignment();
        $this->publish($assignment, 'assignment_created');
    }

    public function onAssignmentReplaced(WorkoutAssignmentReplacedEvent $event): void
    {
        $assignment = $event->getAssignment();
        $this->publish($assignment, 'assignment_replaced');
    }

    public function onScheduleExerciseChanged(WorkoutScheduleExerciseChangedEvent $event): void
    {
        foreach ($this->assignments->findActiveForSchedule($event->getSchedule()) as $assignment) {
            $this->publish($assignment, 'schedule_exercise_' . $event->getChangeType());
        }
    }

    private function publish(WorkoutAssignment $assignment, string $changeType): void
    {
        $payload = json_encode([
            'assignmentId' => (string) $assignment->getId(),
            'scheduleId' => (string) $assignment->getSchedule()->getId(),
            'changeType' => $changeType,
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update(self::memberTopicFor($assignment), $payload));
    }

    public static function memberTopicFor(WorkoutAssignment $assignment): string
    {
        return sprintf('members/%s/assignment-updates', $assignment->getMember()->getId());
    }
}

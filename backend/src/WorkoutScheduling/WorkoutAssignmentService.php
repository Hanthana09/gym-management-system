<?php

namespace App\WorkoutScheduling;

use App\Entity\User;
use App\Entity\WorkoutAssignment;
use App\Entity\WorkoutSchedule;
use App\Event\WorkoutAssignmentCreatedEvent;
use App\Event\WorkoutAssignmentReplacedEvent;
use App\Repository\WorkoutAssignmentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * setly-phase-workout-scheduling.md §4: assign/replace, exactly per the
 * five numbered steps there. Authorization (schedule belongs to this
 * coach, gym-scoping) is the controller's Voter-checked job before calling
 * in here — same split ExpenseService/PtSessionService already establish
 * in this codebase; this service is the persistence layer underneath it.
 *
 * Domain events dispatch via EventDispatcher, not Messenger — deviates
 * from the phase doc's literal wording in favor of this codebase's actual,
 * established convention for post-commit domain events (see
 * PtSessionService for the identical shape: persist+flush, then
 * dispatcher->dispatch() once the write has landed). The "post-commit, not
 * inside the transaction" timing the doc asks for is preserved regardless
 * of mechanism: both dispatches below happen only after
 * wrapInTransaction() has returned successfully.
 */
class WorkoutAssignmentService
{
    public function __construct(
        private readonly WorkoutAssignmentRepository $assignments,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /** @throws AssignmentConflictException if a concurrent request already created the active row for this (coach, member) pair */
    public function assign(WorkoutSchedule $schedule, User $member, User $coach): WorkoutAssignment
    {
        try {
            [$assignment, $replaced] = $this->em->wrapInTransaction(function () use ($schedule, $member, $coach) {
                $existing = $this->assignments->findActiveForCoachAndMember($coach, $member);
                if ($existing !== null) {
                    // Flushed separately, before the new row is even
                    // persisted: Doctrine's UnitOfWork issues all inserts
                    // before updates within a single flush() regardless of
                    // call order, so without this the new `active` INSERT
                    // would race the old row's own still-`active` value
                    // against the partial unique index and self-conflict —
                    // not a real concurrent request, just statement
                    // ordering within one transaction.
                    $existing->markReplaced();
                    $this->em->flush();
                }

                $assignment = new WorkoutAssignment($schedule, $member, $coach, new \DateTimeImmutable('today'));
                $this->em->persist($assignment);
                $this->em->flush();

                return [$assignment, $existing];
            });
        } catch (UniqueConstraintViolationException) {
            // §4: "even if two requests race, the DB rejects the second
            // `active` row for the same coach-member pair, and the service
            // catches that as a conflict rather than relying on the
            // transaction alone."
            throw new AssignmentConflictException();
        }

        $this->dispatcher->dispatch(new WorkoutAssignmentCreatedEvent($assignment), WorkoutAssignmentCreatedEvent::NAME);
        if ($replaced !== null) {
            $this->dispatcher->dispatch(new WorkoutAssignmentReplacedEvent($replaced), WorkoutAssignmentReplacedEvent::NAME);
        }

        return $assignment;
    }
}

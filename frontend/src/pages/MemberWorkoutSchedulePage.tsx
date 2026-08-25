import { useEffect, useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { MEMBER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Tabs } from '../components/ui'
import { ExercisePicker, type PickerItem } from '../components/ExercisePicker'
import { ApiError } from '../lib/apiClient'
import { useMyAssignments } from '../workout-scheduling/useMyAssignments'
import { useAssignmentExercises } from '../workout-scheduling/useAssignmentExercises'

/**
 * setly-phase-workout-scheduling.md frontend tasks #3/#4/#5: schedule
 * switcher (only rendered with >1 active assignment), the scoped exercise
 * picker (never the unscoped catalog), and the logging form. A 403 from
 * ExerciseLogVoter is shown as the exact message the backend already
 * returns ("This exercise isn't part of your current schedule") rather
 * than a generic error.
 */
export function MemberWorkoutSchedulePage() {
  const { assignments, loaded: assignmentsLoaded } = useMyAssignments()
  const [selectedAssignmentId, setSelectedAssignmentId] = useState<string | null>(null)

  useEffect(() => {
    if (assignments.length > 0 && !assignments.some((a) => a.id === selectedAssignmentId)) {
      setSelectedAssignmentId(assignments[0].id)
    }
    if (assignments.length === 0) {
      setSelectedAssignmentId(null)
    }
  }, [assignments, selectedAssignmentId])

  return (
    <div className="h-dvh">
      <NavShell role="member" title="Gym" navItems={MEMBER_NAV_ITEMS} activeHref="/member/workouts">
        <div className="mx-auto flex max-w-4xl flex-col gap-4">
          <Card>
            <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">My workout</h2>

            {!assignmentsLoaded ? (
              <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
            ) : assignments.length === 0 ? (
              <p className="py-6 text-center text-sm text-ink-soft">Your coach hasn't assigned you a workout schedule yet.</p>
            ) : (
              <>
                {assignments.length > 1 ? (
                  <Tabs
                    className="mb-4"
                    items={assignments.map((a) => ({ value: a.id, label: a.scheduleName }))}
                    value={selectedAssignmentId ?? assignments[0].id}
                    onChange={setSelectedAssignmentId}
                  />
                ) : (
                  <p className="mb-4 text-sm text-ink-soft">
                    {assignments[0].scheduleName} · assigned by {assignments[0].coachName}
                  </p>
                )}
              </>
            )}
          </Card>

          {selectedAssignmentId ? <AssignmentExercisesCard assignmentId={selectedAssignmentId} /> : null}
        </div>
      </NavShell>
    </div>
  )
}

function AssignmentExercisesCard({ assignmentId }: { assignmentId: string }) {
  const [muscle, setMuscle] = useState('')
  const [equipment, setEquipment] = useState('')
  const { exercises, loaded, log } = useAssignmentExercises(assignmentId, muscle, equipment)
  const [logging, setLogging] = useState<PickerItem | null>(null)

  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">Exercises</h2>

      <ExercisePicker
        items={exercises.map((e) => ({
          id: e.exerciseId,
          name: e.exerciseName,
          category: e.primaryMuscles?.[0] ?? '',
          equipment: e.equipment ?? null,
          posterUrl: e.posterUrl ?? null,
        }))}
        loaded={loaded}
        onSelect={setLogging}
        selectedId={logging?.id}
        muscleFilter={muscle}
        onMuscleFilterChange={setMuscle}
        equipmentFilter={equipment}
        onEquipmentFilterChange={setEquipment}
        emptyMessage="No exercises in this schedule yet."
      />

      <LogExerciseModal exercise={logging} onClose={() => setLogging(null)} onLog={log} />
    </Card>
  )
}

interface LogExerciseModalProps {
  exercise: PickerItem | null
  onClose: () => void
  onLog: (exerciseId: string, sets: number, reps: number, weight: string, notes: string) => Promise<unknown>
}

function LogExerciseModal({ exercise, onClose, onLog }: LogExerciseModalProps) {
  const [sets, setSets] = useState('3')
  const [reps, setReps] = useState('10')
  const [weight, setWeight] = useState('')
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)

  useEffect(() => {
    setError(null)
    setSuccess(false)
  }, [exercise])

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!exercise) return

    setSubmitting(true)
    setError(null)
    try {
      await onLog(exercise.id, Number(sets), Number(reps), weight, notes)
      setSuccess(true)
    } catch (err) {
      // ExerciseLogVoter's 403 already carries a clear, specific message —
      // shown verbatim rather than a generic "something went wrong".
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open={exercise !== null} onClose={onClose} title={exercise?.name ?? 'Log exercise'}>
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <div className="grid grid-cols-2 gap-3">
          <Input label="Sets completed" type="number" min={1} value={sets} onChange={(e) => setSets(e.target.value)} />
          <Input label="Reps completed" type="number" min={1} value={reps} onChange={(e) => setReps(e.target.value)} />
        </div>
        <Input label="Weight (optional)" type="number" min={0} step="0.01" value={weight} onChange={(e) => setWeight(e.target.value)} />
        <Input label="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        {success ? <p className="text-sm text-green-700">Logged.</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Logging…' : 'Log it'}
        </Button>
      </form>
    </Modal>
  )
}

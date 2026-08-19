import { useEffect, useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Select } from '../components/ui'
import { ExercisePicker, type PickerItem } from '../components/ExercisePicker'
import { ExerciseDetailModal } from '../components/ExerciseDetailModal'
import { ApiError } from '../lib/apiClient'
import { useExercises } from '../exercises/useExercises'
import { useCoachSchedules } from '../workout-scheduling/useCoachSchedules'
import { useAssignSchedule } from '../workout-scheduling/useAssignSchedule'
import type { WorkoutScheduleDto, WorkoutScheduleExerciseDto } from '../workout-scheduling/types'

const SCHEDULE_TYPES = [
  { value: 'strength', label: 'Strength' },
  { value: 'cardio', label: 'Cardio' },
  { value: 'hypertrophy', label: 'Hypertrophy' },
  { value: 'mobility', label: 'Mobility' },
  { value: 'custom', label: 'Custom' },
]

/**
 * setly-phase-workout-scheduling.md frontend tasks #1/#2: schedule
 * builder (CRUD for WorkoutSchedule + its line items, using the shared
 * ExercisePicker) and the assign flow (member + schedule + replace
 * confirmation). One page, three stacked sections — list/create, builder,
 * assign — since a Coach naturally moves top-to-bottom through them.
 */
export function CoachWorkoutSchedulesPage() {
  const { schedules, loaded: schedulesLoaded, createSchedule, getSchedule, addExercise, updateExercise, removeExercise } = useCoachSchedules()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [detail, setDetail] = useState<WorkoutScheduleDto | null>(null)

  async function selectSchedule(id: string) {
    setSelectedId(id)
    const full = await getSchedule(id)
    setDetail(full)
  }

  async function refreshDetail() {
    if (!selectedId) return
    const full = await getSchedule(selectedId)
    setDetail(full)
  }

  return (
    <div className="h-dvh">
      <NavShell role="coach" title="Gym" navItems={COACH_NAV_ITEMS} activeHref="/coach/workout-schedules">
        <div className="mx-auto flex max-w-4xl flex-col gap-4">
          <ScheduleListCard
            schedules={schedules}
            loaded={schedulesLoaded}
            selectedId={selectedId}
            onSelect={selectSchedule}
            onCreate={async (name, type) => {
              const created = await createSchedule(name, type)
              await selectSchedule(created.id)
            }}
          />

          {detail ? (
            <>
              <ScheduleBuilderCard
                schedule={detail}
                onAddExercise={async (exerciseId, dayNumber, order, sets, reps, restSeconds, notes) => {
                  await addExercise(detail.id, exerciseId, dayNumber, order, sets, reps, restSeconds, notes)
                  await refreshDetail()
                }}
                onUpdateExercise={async (lineId, patch) => {
                  await updateExercise(lineId, patch)
                  await refreshDetail()
                }}
                onRemoveExercise={async (lineId) => {
                  await removeExercise(lineId)
                  await refreshDetail()
                }}
              />

              <AssignCard scheduleId={detail.id} />
            </>
          ) : null}
        </div>
      </NavShell>
    </div>
  )
}

interface ScheduleListCardProps {
  schedules: WorkoutScheduleDto[]
  loaded: boolean
  selectedId: string | null
  onSelect: (id: string) => void
  onCreate: (name: string, type: string) => Promise<void>
}

function ScheduleListCard({ schedules, loaded, selectedId, onSelect, onCreate }: ScheduleListCardProps) {
  const [name, setName] = useState('')
  const [type, setType] = useState(SCHEDULE_TYPES[0].value)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!name.trim()) {
      setError('Name is required.')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onCreate(name.trim(), type)
      setName('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">Workout schedules</h2>

      <form className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end" onSubmit={handleSubmit}>
        <Input label="Schedule name" value={name} onChange={(e) => setName(e.target.value)} placeholder="12-Week Strength Block" />
        <Select label="Type" value={type} onChange={(e) => setType(e.target.value)} options={SCHEDULE_TYPES} />
        <Button type="submit" disabled={submitting}>
          {submitting ? 'Creating…' : 'New schedule'}
        </Button>
      </form>
      {error ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}

      {!loaded ? (
        <p className="py-4 text-center text-sm text-ink-soft">Loading…</p>
      ) : schedules.length === 0 ? (
        <p className="py-4 text-center text-sm text-ink-soft">No schedules yet — create one above.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {schedules.map((schedule) => (
            <li key={schedule.id}>
              <button
                type="button"
                onClick={() => onSelect(schedule.id)}
                className={`min-h-touch w-full rounded-md border px-3 py-2 text-left text-sm transition-colors ${
                  selectedId === schedule.id ? 'border-ink bg-paper-dim font-medium' : 'border-line hover:bg-paper-dim'
                }`}
              >
                {schedule.name} <span className="font-mono text-xs text-ink-soft uppercase">· {schedule.type} · {schedule.status}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

interface ScheduleBuilderCardProps {
  schedule: WorkoutScheduleDto
  onAddExercise: (exerciseId: string, dayNumber: number, order: number, sets: number, reps: number, restSeconds: number | null, notes: string) => Promise<void>
  onUpdateExercise: (lineId: string, patch: Partial<Pick<WorkoutScheduleExerciseDto, 'dayNumber' | 'order' | 'sets' | 'reps' | 'restSeconds' | 'notes'>>) => Promise<void>
  onRemoveExercise: (lineId: string) => Promise<void>
}

function ScheduleBuilderCard({ schedule, onAddExercise, onUpdateExercise, onRemoveExercise }: ScheduleBuilderCardProps) {
  const [pickerOpen, setPickerOpen] = useState(false)
  const lines = [...(schedule.exercises ?? [])].sort((a, b) => a.dayNumber - b.dayNumber || a.order - b.order)

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="font-display text-base font-semibold tracking-wide text-ink uppercase">{schedule.name}</h2>
        <Button variant="secondary" onClick={() => setPickerOpen(true)}>
          Add exercise
        </Button>
      </div>

      {lines.length === 0 ? (
        <p className="py-4 text-center text-sm text-ink-soft">No exercises yet — add one above.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {lines.map((line) => (
            <ScheduleLineRow key={line.id} line={line} onUpdate={onUpdateExercise} onRemove={onRemoveExercise} />
          ))}
        </ul>
      )}

      <AddExerciseModal
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        onAdd={async (exerciseId, dayNumber, order, sets, reps, restSeconds, notes) => {
          await onAddExercise(exerciseId, dayNumber, order, sets, reps, restSeconds, notes)
          setPickerOpen(false)
        }}
      />
    </Card>
  )
}

function ScheduleLineRow({
  line,
  onUpdate,
  onRemove,
}: {
  line: WorkoutScheduleExerciseDto
  onUpdate: (lineId: string, patch: Partial<Pick<WorkoutScheduleExerciseDto, 'dayNumber' | 'order' | 'sets' | 'reps' | 'restSeconds' | 'notes'>>) => Promise<void>
  onRemove: (lineId: string) => Promise<void>
}) {
  const [sets, setSets] = useState(String(line.sets))
  const [reps, setReps] = useState(String(line.reps))
  const [saving, setSaving] = useState(false)
  const [removing, setRemoving] = useState(false)

  async function handleSave() {
    setSaving(true)
    try {
      await onUpdate(line.id, { sets: Number(sets), reps: Number(reps) })
    } finally {
      setSaving(false)
    }
  }

  async function handleRemove() {
    setRemoving(true)
    try {
      await onRemove(line.id)
    } finally {
      setRemoving(false)
    }
  }

  return (
    <li className="flex flex-col gap-2 rounded-md border border-line p-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{line.exerciseName}</p>
        <p className="font-mono text-xs text-ink-soft">Day {line.dayNumber} · order {line.order}</p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <input
          aria-label="Sets"
          type="number"
          min={1}
          value={sets}
          onChange={(e) => setSets(e.target.value)}
          className="min-h-touch w-16 rounded-md border border-line bg-card px-2 text-center text-sm text-ink"
        />
        <span className="text-xs text-ink-soft">×</span>
        <input
          aria-label="Reps"
          type="number"
          min={1}
          value={reps}
          onChange={(e) => setReps(e.target.value)}
          className="min-h-touch w-16 rounded-md border border-line bg-card px-2 text-center text-sm text-ink"
        />
        <Button variant="secondary" onClick={handleSave} disabled={saving}>
          {saving ? 'Saving…' : 'Save'}
        </Button>
        <Button variant="danger" onClick={handleRemove} disabled={removing}>
          {removing ? 'Removing…' : 'Remove'}
        </Button>
      </div>
    </li>
  )
}

interface AddExerciseModalProps {
  open: boolean
  onClose: () => void
  onAdd: (exerciseId: string, dayNumber: number, order: number, sets: number, reps: number, restSeconds: number | null, notes: string) => Promise<void>
}

function AddExerciseModal({ open, onClose, onAdd }: AddExerciseModalProps) {
  const [muscle, setMuscle] = useState('')
  const [equipment, setEquipment] = useState('')
  const [page, setPage] = useState(1)
  const { exercises, totalItems, itemsPerPage, loaded } = useExercises(muscle, equipment, '', page)
  const [selected, setSelected] = useState<PickerItem | null>(null)
  const [dayNumber, setDayNumber] = useState('1')
  const [order, setOrder] = useState('1')
  const [sets, setSets] = useState('3')
  const [reps, setReps] = useState('10')
  const [restSeconds, setRestSeconds] = useState('')
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [detailsId, setDetailsId] = useState<string | null>(null)

  async function handleAdd() {
    if (!selected) {
      setError('Choose an exercise first.')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onAdd(selected.id, Number(dayNumber), Number(order), Number(sets), Number(reps), restSeconds ? Number(restSeconds) : null, notes)
      setSelected(null)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Add exercise"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={handleAdd} disabled={submitting || !selected}>
            {submitting ? 'Adding…' : 'Add to schedule'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <ExercisePicker
          items={exercises}
          loaded={loaded}
          onSelect={setSelected}
          selectedId={selected?.id}
          muscleFilter={muscle}
          onMuscleFilterChange={(value) => {
            setMuscle(value)
            setPage(1)
          }}
          equipmentFilter={equipment}
          onEquipmentFilterChange={(value) => {
            setEquipment(value)
            setPage(1)
          }}
          pagination={{ page, totalItems, itemsPerPage, onPageChange: setPage }}
          onViewDetails={(item) => setDetailsId(item.id)}
          emptyMessage="No exercises in the catalog yet."
        />
        <ExerciseDetailModal exerciseId={detailsId} onClose={() => setDetailsId(null)} />

        {selected ? (
          <div className="grid grid-cols-2 gap-3">
            <Input label="Day" type="number" min={1} value={dayNumber} onChange={(e) => setDayNumber(e.target.value)} />
            <Input label="Order" type="number" min={1} value={order} onChange={(e) => setOrder(e.target.value)} />
            <Input label="Sets" type="number" min={1} value={sets} onChange={(e) => setSets(e.target.value)} />
            <Input label="Reps" type="number" min={1} value={reps} onChange={(e) => setReps(e.target.value)} />
            <Input label="Rest (seconds)" type="number" min={0} value={restSeconds} onChange={(e) => setRestSeconds(e.target.value)} />
            <Input label="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
        ) : null}

        {error ? <p className="text-sm text-red-600">{error}</p> : null}
      </div>
    </Modal>
  )
}

function AssignCard({ scheduleId }: { scheduleId: string }) {
  const { members, loaded, assign, refresh } = useAssignSchedule()
  const [memberId, setMemberId] = useState('')
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)

  useEffect(() => {
    setMemberId('')
    setSuccess(false)
  }, [scheduleId])

  const selectedMember = members.find((m) => m.id === memberId)

  function handleAssignClick() {
    setError(null)
    setSuccess(false)
    if (!memberId) {
      setError('Choose a member first.')
      return
    }
    if (selectedMember?.hasActiveAssignmentFromMe) {
      setConfirmOpen(true)
      return
    }
    void doAssign()
  }

  async function doAssign() {
    setAssigning(true)
    setError(null)
    try {
      await assign(scheduleId, memberId)
      setSuccess(true)
      setConfirmOpen(false)
      await refresh()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setConfirmOpen(false)
    } finally {
      setAssigning(false)
    }
  }

  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">Assign to a member</h2>

      {!loaded ? (
        <p className="text-sm text-ink-soft">Loading members…</p>
      ) : members.length === 0 ? (
        <p className="text-sm text-ink-soft">No members in the gym yet.</p>
      ) : (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <Select
            label="Member"
            value={memberId}
            onChange={(e) => setMemberId(e.target.value)}
            options={[{ value: '', label: 'Choose a member…' }, ...members.map((m) => ({ value: m.id, label: m.name }))]}
          />
          <Button onClick={handleAssignClick} disabled={assigning}>
            {assigning ? 'Assigning…' : 'Assign'}
          </Button>
        </div>
      )}

      {error ? <p className="mt-3 text-sm text-red-600">{error}</p> : null}
      {success ? <p className="mt-3 text-sm text-green-700">Assigned.</p> : null}

      <Modal
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        title="Replace current schedule?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirmOpen(false)}>
              Cancel
            </Button>
            <Button onClick={doAssign} disabled={assigning}>
              {assigning ? 'Replacing…' : 'Replace and assign'}
            </Button>
          </>
        }
      >
        <p className="text-sm text-ink">
          {selectedMember?.name} already has an active schedule from you. Assigning this one will replace their
          current schedule — their logged history stays intact.
        </p>
      </Modal>
    </Card>
  )
}

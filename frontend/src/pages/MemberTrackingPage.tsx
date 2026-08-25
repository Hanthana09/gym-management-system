import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { MEMBER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Select, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useWorkouts } from '../personal-tracking/useWorkouts'
import { useBodyMetrics } from '../personal-tracking/useBodyMetrics'
import { ProgressChart } from '../personal-tracking/ProgressChart'
import type { WorkoutLogDto } from '../personal-tracking/types'

const WORKOUT_TYPES = ['Run', 'Swim', 'Cycle', 'Strength', 'Yoga', 'Other']

function formatWorkoutDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
}

/**
 * roadmap Phase 8. Three sections on one page: a quick log-workout form
 * (type + duration only — "fast to fill in right after a workout"),
 * workout history as Ticket rows (DESIGN-SYSTEM.md §3/§4 — a workout log
 * entry is the same kind of discrete, scannable event as a check-in or a
 * PT session), and the body-metric progress chart with its own compact
 * entry form (needed so functional requirements §7.2's empty state has
 * something real to invite the Member into, even though the roadmap's
 * frontend bullets only name the chart itself).
 */
export function MemberTrackingPage() {
  const { workouts, loaded: workoutsLoaded, logWorkout } = useWorkouts()
  const { bodyMetrics, loaded: metricsLoaded, logBodyMetric } = useBodyMetrics()

  return (
    <div className="h-dvh">
      <NavShell role="member" title="Gym" navItems={MEMBER_NAV_ITEMS} activeHref="/member/tracking">
        <div className="mx-auto grid max-w-6xl grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
          <div className="flex flex-col gap-4 lg:col-span-2">
            <LogWorkoutForm onLog={logWorkout} />

            <Card>
              <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
                Workout history
              </h2>
              {!workoutsLoaded ? (
                <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
              ) : workouts.length === 0 ? (
                <p className="py-6 text-center text-sm text-ink-soft">No workouts logged yet.</p>
              ) : (
                <ul className="flex flex-col gap-3">
                  {workouts.map((workout) => (
                    <li key={workout.id}>
                      <WorkoutRow workout={workout} />
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>

          <Card>
            <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
              Progress
            </h2>
            <LogBodyMetricForm onLog={logBodyMetric} />

            <div className="mt-4">
              {!metricsLoaded ? (
                <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
              ) : bodyMetrics.length === 0 ? (
                // functional requirements §7.2: named acceptance criterion —
                // an inviting empty state, not a broken/blank chart.
                <p className="py-6 text-center text-sm text-ink-soft">
                  No entries yet — log your weight above to start seeing your trend here.
                </p>
              ) : (
                <ProgressChart data={bodyMetrics} />
              )}
            </div>
          </Card>
        </div>
      </NavShell>
    </div>
  )
}

function WorkoutRow({ workout }: { workout: WorkoutLogDto }) {
  return (
    <Ticket className="flex items-center justify-between gap-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{workout.type}</p>
        <p className="font-mono text-xs text-ink-soft">
          {formatWorkoutDate(workout.date)} · {workout.durationMinutes} min
        </p>
      </div>
    </Ticket>
  )
}

/**
 * roadmap Phase 8: "minimal required fields (type, duration) ... fast to
 * fill in right after a workout." No date field (always today) and a
 * Select instead of free text — the fewer taps, the better.
 */
function LogWorkoutForm({ onLog }: { onLog: (type: string, durationMinutes: number) => Promise<WorkoutLogDto> }) {
  const [type, setType] = useState(WORKOUT_TYPES[0])
  const [durationMinutes, setDurationMinutes] = useState('30')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await onLog(type, Number(durationMinutes))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
        Log a workout
      </h2>
      <form className="flex flex-col gap-4 sm:flex-row sm:items-end" onSubmit={handleSubmit}>
        <div className="flex-1">
          <Select label="Type" value={type} onChange={(event) => setType(event.target.value)} options={WORKOUT_TYPES.map((t) => ({ value: t, label: t }))} />
        </div>
        <div className="flex-1">
          <Input
            label="Duration (min)"
            type="number"
            min="1"
            value={durationMinutes}
            onChange={(event) => setDurationMinutes(event.target.value)}
            required
          />
        </div>
        <Button type="submit" fullWidth className="sm:w-auto" disabled={submitting}>
          {submitting ? 'Logging…' : 'Log workout'}
        </Button>
      </form>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
    </Card>
  )
}

function LogBodyMetricForm({ onLog }: { onLog: (weightKg: string, bodyFatPct?: string) => Promise<unknown> }) {
  const [weightKg, setWeightKg] = useState('')
  const [bodyFatPct, setBodyFatPct] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (!weightKg) return

    setSubmitting(true)
    try {
      await onLog(weightKg, bodyFatPct || undefined)
      setWeightKg('')
      setBodyFatPct('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
      <div className="flex-1">
        <Input
          label="Weight (kg)"
          type="number"
          step="0.1"
          min="0"
          value={weightKg}
          onChange={(event) => setWeightKg(event.target.value)}
          required
        />
      </div>
      <div className="flex-1">
        <Input
          label="Body fat % (optional)"
          type="number"
          step="0.1"
          min="0"
          value={bodyFatPct}
          onChange={(event) => setBodyFatPct(event.target.value)}
        />
      </div>
      <Button type="submit" variant="secondary" fullWidth disabled={submitting || !weightKg}>
        {submitting ? 'Saving…' : 'Log weight'}
      </Button>
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
    </form>
  )
}

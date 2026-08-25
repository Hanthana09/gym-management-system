import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { MEMBER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Select, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useCoaches } from '../personal-training/useCoaches'
import { useMySessions } from '../personal-training/useMySessions'
import type { PtSessionDto, PtSessionStatus } from '../personal-training/types'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'

// DESIGN-SYSTEM.md §3 "Tag/pill" typographic pattern, applied inline —
// these statuses keep their own semantic colors, not the role palette.
const STATUS_STYLES: Record<PtSessionStatus, string> = {
  pending: 'bg-amber-100 text-amber-800',
  confirmed: 'bg-green-100 text-green-800',
  completed: 'bg-blue-100 text-blue-800',
  declined: 'bg-red-100 text-red-800',
  cancelled: 'bg-gray-100 text-gray-600',
}

function StatusTag({ status }: { status: PtSessionStatus }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${STATUS_STYLES[status]}`}>
      {status}
    </span>
  )
}

function formatScheduledAt(iso: string): string {
  return new Date(iso).toLocaleString([], {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

/**
 * roadmap Phase 6: coach picker + date/time as native inputs (mobile-first
 * default at every breakpoint — a calendar widget is "acceptable" at md:+
 * per the roadmap, not required, so the simpler native inputs are used
 * throughout). "My sessions" below uses the Ticket pattern
 * (DESIGN-SYSTEM.md §3/§4) — each row is exactly the kind of discrete,
 * scannable event that pattern was designed for.
 */
export function MemberSessionsPage() {
  const { branches } = useBranches()
  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)
  const effectiveBranchId = selectedBranchId ?? defaultBranchId(branches)
  const { coaches, loaded: coachesLoaded } = useCoaches(effectiveBranchId)
  const { sessions, loaded: sessionsLoaded, requestSession, cancel } = useMySessions()

  return (
    <div className="h-dvh">
      <NavShell role="member" title="Gym" navItems={MEMBER_NAV_ITEMS} activeHref="/member/sessions">
        <div className="mx-auto grid max-w-6xl grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
          <div className="flex flex-col gap-4">
            {/* functional requirements §14.3: any branch with an assigned Coach, not just the Member's own enrolling branch. Absent for single-branch gyms. */}
            <BranchSwitcher branches={branches} value={effectiveBranchId} onChange={setSelectedBranchId} />
            <BookingForm
              coaches={coaches}
              coachesLoaded={coachesLoaded}
              branchId={effectiveBranchId}
              onRequest={requestSession}
            />
          </div>

          <Card className="lg:col-span-2">
            <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
              My sessions
            </h2>

            {!sessionsLoaded ? (
              <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
            ) : sessions.length === 0 ? (
              <p className="py-6 text-center text-sm text-ink-soft">No sessions booked yet.</p>
            ) : (
              <ul className="flex flex-col gap-3">
                {sessions.map((session) => (
                  <li key={session.id}>
                    <SessionRow session={session} onCancel={cancel} />
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </NavShell>
    </div>
  )
}

function SessionRow({ session, onCancel }: { session: PtSessionDto; onCancel: (id: string) => Promise<void> }) {
  const [cancelling, setCancelling] = useState(false)

  async function handleCancel() {
    setCancelling(true)
    try {
      await onCancel(session.id)
    } finally {
      setCancelling(false)
    }
  }

  return (
    <Ticket className="flex items-center justify-between gap-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{session.coach.name}</p>
        <p className="font-mono text-xs text-ink-soft">
          {formatScheduledAt(session.scheduledAt)} · {session.durationMinutes} min
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <StatusTag status={session.status} />
        {session.status === 'pending' ? (
          <Button variant="secondary" onClick={handleCancel} disabled={cancelling}>
            {cancelling ? 'Cancelling…' : 'Cancel'}
          </Button>
        ) : null}
      </div>
    </Ticket>
  )
}

interface BookingFormProps {
  coaches: { id: string; name: string }[]
  coachesLoaded: boolean
  branchId: string | null
  onRequest: (
    coachUserId: string,
    scheduledAt: string,
    durationMinutes: number,
    branchId?: string | null,
  ) => Promise<PtSessionDto>
}

function BookingForm({ coaches, coachesLoaded, branchId, onRequest }: BookingFormProps) {
  const [coachUserId, setCoachUserId] = useState('')
  const [date, setDate] = useState('')
  const [time, setTime] = useState('')
  const [durationMinutes, setDurationMinutes] = useState('60')
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const selectedCoach = coachUserId || coaches[0]?.id || ''

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSuccess(false)

    if (!selectedCoach || !date || !time) {
      setError('Please choose a coach, date, and time.')
      return
    }

    setSubmitting(true)
    try {
      const scheduledAt = new Date(`${date}T${time}`).toISOString()
      await onRequest(selectedCoach, scheduledAt, Number(durationMinutes), branchId)
      setSuccess(true)
      setDate('')
      setTime('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
        Book a session
      </h2>

      {!coachesLoaded ? (
        <p className="text-sm text-ink-soft">Loading coaches…</p>
      ) : coaches.length === 0 ? (
        <p className="text-sm text-ink-soft">No coaches available to book with yet.</p>
      ) : (
        <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
          <Select
            label="Coach"
            value={selectedCoach}
            onChange={(event) => setCoachUserId(event.target.value)}
            options={coaches.map((coach) => ({ value: coach.id, label: coach.name }))}
          />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <DateField label="Date" value={date} onChange={setDate} />
            <TimeField label="Time" value={time} onChange={setTime} />
          </div>
          <Select
            label="Duration"
            value={durationMinutes}
            onChange={(event) => setDurationMinutes(event.target.value)}
            options={[
              { value: '30', label: '30 minutes' },
              { value: '45', label: '45 minutes' },
              { value: '60', label: '60 minutes' },
              { value: '90', label: '90 minutes' },
            ]}
          />
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {success ? <p className="text-sm text-green-700">Request sent — you'll be notified once your coach responds.</p> : null}
          <Button type="submit" fullWidth disabled={submitting}>
            {submitting ? 'Sending…' : 'Send request'}
          </Button>
        </form>
      )}
    </Card>
  )
}

/**
 * Native `<input type="date">`/`type="time">` (roadmap Phase 6) — not the
 * shared `Input` primitive, since a labeled text field's error/hint slots
 * aren't needed here and the native pickers already give the right mobile
 * keyboard/UX on their own (same reasoning as Phase 2's OtpDigitInputs).
 */
function DateField({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <div className="w-full">
      <label className="mb-1.5 block text-sm font-medium text-ink">{label}</label>
      <input
        type="date"
        required
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="min-h-touch w-full rounded-md border border-line bg-card px-3 text-base text-ink focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1"
      />
    </div>
  )
}

function TimeField({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <div className="w-full">
      <label className="mb-1.5 block text-sm font-medium text-ink">{label}</label>
      <input
        type="time"
        required
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="min-h-touch w-full rounded-md border border-line bg-card px-3 text-base text-ink focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1"
      />
    </div>
  )
}

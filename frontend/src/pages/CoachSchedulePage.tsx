import { useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Ticket } from '../components/ui'
import { useCoachSchedule } from '../personal-training/useCoachSchedule'
import type { PtSessionDto, PtSessionStatus } from '../personal-training/types'

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

function startOfWeek(date: Date): Date {
  const result = new Date(date)
  const day = result.getDay()
  // Monday-aligned week, matching front-desk/gym scheduling convention.
  const diff = (day === 0 ? -6 : 1) - day
  result.setDate(result.getDate() + diff)
  result.setHours(0, 0, 0, 0)

  return result
}

function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/**
 * roadmap Phase 6: "list/agenda layout on mobile ... switches to a real
 * calendar grid at lg:." Both views render the same session data via the
 * same AgendaEntry — only the layout differs. Accept/decline are clearly
 * labeled buttons per functional requirements §5.2 / roadmap Phase 6, not
 * icon-only controls, and use the standard primary/secondary button
 * styles — hivis is reserved for check-in (DESIGN-SYSTEM.md), not reused
 * here even though this is also a "confirm" action.
 */
export function CoachSchedulePage() {
  const { sessions, loaded, respond } = useCoachSchedule()
  const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()))

  const weekDays = useMemo(
    () => Array.from({ length: 7 }, (_, i) => new Date(weekStart.getFullYear(), weekStart.getMonth(), weekStart.getDate() + i)),
    [weekStart],
  )

  return (
    <div className="h-dvh">
      <NavShell role="coach" title="Gym" navItems={COACH_NAV_ITEMS} activeHref="/coach/sessions">
        <div className="mx-auto flex max-w-5xl flex-col gap-4">
          <Card>
            <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
              My schedule
            </h2>

            {!loaded ? (
              <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
            ) : sessions.length === 0 ? (
              <p className="py-6 text-center text-sm text-ink-soft">No sessions on your schedule yet.</p>
            ) : (
              <>
                {/* Agenda list — default (mobile/tablet) */}
                <ul className="flex flex-col gap-3 lg:hidden">
                  {sessions.map((session) => (
                    <li key={session.id}>
                      <AgendaEntry session={session} onRespond={respond} />
                    </li>
                  ))}
                </ul>

                {/* Calendar grid — lg: and up */}
                <div className="hidden lg:block">
                  <div className="mb-3 flex items-center justify-between">
                    <Button variant="secondary" onClick={() => setWeekStart((d) => new Date(d.getFullYear(), d.getMonth(), d.getDate() - 7))}>
                      ← Previous week
                    </Button>
                    <p className="text-sm font-medium text-ink">
                      {weekDays[0].toLocaleDateString([], { month: 'short', day: 'numeric' })} –{' '}
                      {weekDays[6].toLocaleDateString([], { month: 'short', day: 'numeric' })}
                    </p>
                    <Button variant="secondary" onClick={() => setWeekStart((d) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + 7))}>
                      Next week →
                    </Button>
                  </div>
                  <div className="grid grid-cols-7 gap-2">
                    {weekDays.map((day) => (
                      <div key={day.toISOString()} className="flex min-h-40 flex-col gap-2 rounded-lg border border-line bg-paper-dim p-2">
                        <p className="text-center text-xs font-semibold tracking-wide text-ink-soft uppercase">
                          {day.toLocaleDateString([], { weekday: 'short', day: 'numeric' })}
                        </p>
                        {sessions
                          .filter((session) => isSameDay(new Date(session.scheduledAt), day))
                          .map((session) => (
                            <AgendaEntry key={session.id} session={session} onRespond={respond} compact />
                          ))}
                      </div>
                    ))}
                  </div>
                </div>
              </>
            )}
          </Card>
        </div>
      </NavShell>
    </div>
  )
}

interface AgendaEntryProps {
  session: PtSessionDto
  onRespond: (id: string, accept: boolean) => Promise<void>
  compact?: boolean
}

function AgendaEntry({ session, onRespond, compact = false }: AgendaEntryProps) {
  const [busy, setBusy] = useState<'accept' | 'decline' | null>(null)

  async function handle(accept: boolean) {
    setBusy(accept ? 'accept' : 'decline')
    try {
      await onRespond(session.id, accept)
    } finally {
      setBusy(null)
    }
  }

  const time = new Date(session.scheduledAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })

  return (
    <Ticket className={compact ? 'flex flex-col gap-1.5 p-2' : 'flex items-center justify-between gap-3'}>
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{session.member.name}</p>
        <p className="font-mono text-xs text-ink-soft">
          {compact ? time : new Date(session.scheduledAt).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
          {' · '}
          {session.durationMinutes} min
        </p>
      </div>
      <div className={compact ? 'flex flex-wrap items-center gap-1.5' : 'flex shrink-0 items-center gap-2'}>
        <StatusTag status={session.status} />
        {session.status === 'pending' ? (
          <>
            <Button variant="secondary" onClick={() => handle(false)} disabled={busy !== null}>
              {busy === 'decline' ? 'Declining…' : 'Decline'}
            </Button>
            <Button onClick={() => handle(true)} disabled={busy !== null}>
              {busy === 'accept' ? 'Accepting…' : 'Accept'}
            </Button>
          </>
        ) : null}
      </div>
    </Ticket>
  )
}

import { useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Ticket } from '../components/ui'
import { ChevronLeftIcon, ChevronRightIcon } from '../components/ui/icons'
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

// Same five statuses, as a solid dot for the mobile month cell (too
// narrow for STATUS_STYLES' text chip) and as the event chip's accent
// border on wider cells — one color source for both densities.
const STATUS_DOT: Record<PtSessionStatus, string> = {
  pending: 'bg-amber-500',
  confirmed: 'bg-green-600',
  completed: 'bg-blue-500',
  declined: 'bg-red-500',
  cancelled: 'bg-gray-400',
}

function StatusTag({ status }: { status: PtSessionStatus }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${STATUS_STYLES[status]}`}>
      {status}
    </span>
  )
}

/**
 * Month-grid cell entry for sm:+ cells (wide enough for a line of text) —
 * a session's full AgendaEntry (Ticket card, name, duration, status pill,
 * accept/decline buttons) doesn't fit three-deep in a single cell, so
 * this is deliberately just a time + name chip in the Google Calendar
 * "event pill" style: a solid left accent bar (STATUS_DOT's color) on a
 * light tint. Clicking a chip selects its day (same as clicking anywhere
 * else in the cell) — the full entry, controls included, is one click
 * away in the panel below the grid.
 */
function MonthEntryChip({ session }: { session: PtSessionDto }) {
  const time = new Date(session.scheduledAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })

  return (
    <span className={`flex items-center gap-1 truncate rounded-sm py-0.5 pr-1 pl-1 text-[10px] leading-tight font-medium ${STATUS_STYLES[session.status]}`}>
      <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${STATUS_DOT[session.status]}`} />
      <span className="truncate">
        {time} {session.member.name}
      </span>
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

/** First-of-month, midnight local time. */
function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

/**
 * Always a fixed 6×7 grid (42 days) so the layout doesn't reflow height
 * between a 4-week-fitting month and a 6-week one — same Monday-aligned
 * week start as the (now-removed) week view, so "today" lines up under
 * the same weekday column a returning user already expects.
 */
function monthMatrix(monthCursor: Date): Date[] {
  const gridStart = startOfWeek(startOfMonth(monthCursor))

  return Array.from({ length: 42 }, (_, i) => new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i))
}

const MAX_VISIBLE_PER_DAY = 3

/**
 * roadmap Phase 6: "list/agenda layout on mobile ... switches to a real
 * calendar grid at lg:." — reworked so the calendar itself is what's
 * visible at every width (a Coach checking their phone couldn't see a
 * calendar at all before this, only a flat unbounded list), just denser
 * at mobile widths: date + up to 4 status dots below 640px (a cell is
 * too narrow there for readable text), the same dots plus a time+name
 * event chip — Google Calendar's own "colored pill" convention — from
 * `sm:` up. A tapped/clicked day becomes the selected day; its full
 * agenda (the same `AgendaEntry` card, accept/decline controls included)
 * renders in the panel below the grid, which doubles as "the list" for
 * mobile instead of a second, redundant flat view. Accept/decline stay
 * clearly labeled buttons per functional requirements §5.2, not
 * icon-only controls, and use the standard primary/secondary button
 * styles — hivis is reserved for check-in (DESIGN-SYSTEM.md), not reused
 * here even though this is also a "confirm" action.
 *
 * Always a full month (6×7) rather than a single week — a Coach needs to
 * see at least a month out to plan around it.
 */
export function CoachSchedulePage() {
  const { sessions, loaded, respond } = useCoachSchedule()
  const today = useMemo(() => new Date(), [])
  const [monthCursor, setMonthCursor] = useState(() => startOfMonth(today))
  const [selectedDay, setSelectedDay] = useState<Date>(today)

  const monthDays = useMemo(() => monthMatrix(monthCursor), [monthCursor])
  const selectedDaySessions = useMemo(
    () => sessions.filter((session) => isSameDay(new Date(session.scheduledAt), selectedDay)),
    [sessions, selectedDay],
  )

  function goToMonth(offset: number) {
    setMonthCursor((d) => new Date(d.getFullYear(), d.getMonth() + offset, 1))
  }

  function goToToday() {
    setMonthCursor(startOfMonth(today))
    setSelectedDay(today)
  }

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
            ) : (
              <>
                {/* Google Calendar-style header: a "Today" pill + prev/next
                    chevrons grouped on the left, the month/year as the
                    prominent title next to them. */}
                <div className="mb-3 flex items-center gap-1 sm:gap-2">
                  <button
                    type="button"
                    onClick={goToToday}
                    className="min-h-touch rounded-full border border-line px-3 text-sm font-medium text-ink hover:bg-paper-dim"
                  >
                    Today
                  </button>
                  <button
                    type="button"
                    onClick={() => goToMonth(-1)}
                    aria-label="Previous month"
                    className="flex min-h-touch min-w-touch items-center justify-center rounded-full text-ink hover:bg-paper-dim"
                  >
                    <ChevronLeftIcon width={18} height={18} />
                  </button>
                  <button
                    type="button"
                    onClick={() => goToMonth(1)}
                    aria-label="Next month"
                    className="flex min-h-touch min-w-touch items-center justify-center rounded-full text-ink hover:bg-paper-dim"
                  >
                    <ChevronRightIcon width={18} height={18} />
                  </button>
                  <p className="font-display ml-1 text-lg font-semibold text-ink sm:text-xl">
                    {monthCursor.toLocaleDateString([], { month: 'long', year: 'numeric' })}
                  </p>
                </div>

                <div className="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-line bg-line">
                  {monthDays.slice(0, 7).map((day) => (
                    <p key={day.toISOString()} className="bg-paper-dim py-1.5 text-center text-xs font-semibold tracking-wide text-ink-soft uppercase">
                      <span className="sm:hidden">{day.toLocaleDateString([], { weekday: 'narrow' })}</span>
                      <span className="hidden sm:inline">{day.toLocaleDateString([], { weekday: 'short' })}</span>
                    </p>
                  ))}
                  {monthDays.map((day) => {
                    const daySessions = sessions.filter((session) => isSameDay(new Date(session.scheduledAt), day))
                    const isCurrentMonth = day.getMonth() === monthCursor.getMonth()
                    const isToday = isSameDay(day, today)
                    const isSelected = isSameDay(day, selectedDay)
                    const overflowCount = daySessions.length - MAX_VISIBLE_PER_DAY

                    return (
                      <button
                        key={day.toISOString()}
                        type="button"
                        onClick={() => setSelectedDay(day)}
                        className={`flex min-h-14 flex-col gap-1 p-1 text-left transition-colors sm:min-h-24 sm:p-1.5 lg:min-h-28 ${
                          isSelected ? 'bg-card ring-2 ring-inset ring-ink' : 'bg-card hover:bg-paper-dim'
                        }`}
                      >
                        <span
                          className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-mono ${
                            isToday ? 'bg-ink text-paper' : isCurrentMonth ? 'text-ink' : 'text-ink-soft/50'
                          }`}
                        >
                          {day.getDate()}
                        </span>

                        {/* Dots — default (mobile), too narrow for readable text */}
                        <div className="flex flex-wrap gap-0.5 sm:hidden">
                          {daySessions.slice(0, 4).map((session) => (
                            <span key={session.id} className={`h-1.5 w-1.5 shrink-0 rounded-full ${STATUS_DOT[session.status]}`} />
                          ))}
                        </div>

                        {/* Event chips — sm: and up */}
                        <div className="hidden flex-col gap-1 sm:flex">
                          {daySessions.slice(0, MAX_VISIBLE_PER_DAY).map((session) => (
                            <MonthEntryChip key={session.id} session={session} />
                          ))}
                          {overflowCount > 0 ? <p className="text-xs text-ink-soft">+{overflowCount} more</p> : null}
                        </div>
                      </button>
                    )
                  })}
                </div>

                <div className="mt-4 border-t border-line pt-4">
                  <h3 className="mb-3 text-sm font-semibold text-ink">
                    {selectedDay.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })}
                  </h3>
                  {selectedDaySessions.length === 0 ? (
                    <p className="text-sm text-ink-soft">No sessions this day.</p>
                  ) : (
                    <ul className="flex flex-col gap-3">
                      {selectedDaySessions.map((session) => (
                        <li key={session.id}>
                          <AgendaEntry session={session} onRespond={respond} />
                        </li>
                      ))}
                    </ul>
                  )}
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

import { useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Card, Input, Ticket } from '../components/ui'
import { useLiveAttendanceCount } from '../attendance/useLiveAttendanceCount'
import { useAttendanceReport } from '../attendance/useAttendanceReport'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function daysAgo(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)

  return date.toISOString().slice(0, 10)
}

/**
 * "Live attendance counter... readable at a glance on a tablet at the
 * front desk" + "filter by date range" (roadmap Phase 5 / functional
 * requirements §4.2).
 */
export function OwnerDashboardPage() {
  const liveCount = useLiveAttendanceCount()
  const [from, setFrom] = useState(daysAgo(7))
  const [to, setTo] = useState(today())
  const { entries, loading } = useAttendanceReport(from, to)

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/dashboard">
        <div className="mx-auto flex max-w-3xl flex-col gap-4">
          <Card>
            <p className="text-sm text-ink-soft">Check-ins today</p>
            <p className="mt-1 font-display text-5xl font-bold text-ink tabular-nums">
              {liveCount === null ? '—' : liveCount}
            </p>
          </Card>

          <Card>
            <h2 className="mb-3 text-base font-semibold text-ink">Attendance report</h2>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>

            {loading ? (
              <p className="mt-4 text-sm text-ink-soft">Loading…</p>
            ) : entries.length === 0 ? (
              <p className="mt-4 text-sm text-ink-soft">No check-ins in this range.</p>
            ) : (
              <ul className="mt-4 flex flex-col gap-3">
                {entries.map((entry) => (
                  <li key={entry.id}>
                    <Ticket className="flex items-center justify-between text-sm">
                      <span className="font-medium text-ink">{entry.memberName}</span>
                      <span className="font-mono text-xs text-ink-soft">
                        {new Date(entry.checkInAt).toLocaleString([], {
                          month: 'short',
                          day: 'numeric',
                          hour: 'numeric',
                          minute: '2-digit',
                        })}
                      </span>
                    </Ticket>
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

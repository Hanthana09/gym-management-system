const WEEKS_SHOWN = 12
const DAY_LABELS = ['S', 'M', 'T', 'W', 'T', 'F', 'S']

interface AttendanceHeatmapProps {
  /** ISO date strings (YYYY-MM-DD or full timestamps) of every day with at least one check-in. */
  checkInDates: string[]
}

function toDateKey(d: Date): string {
  return d.toISOString().slice(0, 10)
}

/**
 * gym-management-dashboard-redesign.md Phase 2: a GitHub-contribution-
 * style streak grid — role-agnostic (just a list of dates in, no branch/
 * role knowledge). Member's home view uses this for overall consistency,
 * deliberately branch-agnostic per Phase 4's own note. Horizontal scroll
 * is the one widget this phase explicitly allows to overflow on phone
 * widths — everything else must reflow instead.
 */
export function AttendanceHeatmap({ checkInDates }: AttendanceHeatmapProps) {
  const activeDays = new Set(checkInDates.map((d) => d.slice(0, 10)))

  const today = new Date()
  today.setHours(0, 0, 0, 0)
  // Align the grid's last column to the most recent Saturday so full
  // weeks stack cleanly, same convention GitHub's own grid uses.
  const endOfWeek = new Date(today)
  endOfWeek.setDate(endOfWeek.getDate() + (6 - endOfWeek.getDay()))

  const weeks: Date[][] = []
  for (let w = WEEKS_SHOWN - 1; w >= 0; w--) {
    const week: Date[] = []
    for (let d = 0; d < 7; d++) {
      const day = new Date(endOfWeek)
      day.setDate(day.getDate() - w * 7 - (6 - d))
      week.push(day)
    }
    weeks.push(week)
  }

  return (
    <div className="overflow-x-auto">
      <div className="flex gap-1">
        <div className="flex flex-col gap-1 pr-1">
          {DAY_LABELS.map((label, i) => (
            <span key={i} className="flex h-3 w-3 items-center text-[9px] text-ink-soft">
              {i % 2 === 1 ? label : ''}
            </span>
          ))}
        </div>
        <div className="flex gap-1" style={{ minWidth: `${WEEKS_SHOWN * 16}px` }}>
          {weeks.map((week, wi) => (
            <div key={wi} className="flex flex-col gap-1">
              {week.map((day, di) => {
                const key = toDateKey(day)
                const isFuture = day > today
                const isActive = activeDays.has(key)

                return (
                  <div
                    key={di}
                    title={key}
                    className={'h-3 w-3 rounded-sm ' + (isFuture ? 'bg-transparent' : isActive ? 'bg-hivis' : 'bg-paper-dim')}
                  />
                )
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

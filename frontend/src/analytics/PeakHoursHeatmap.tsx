import { ChartCard } from '../components/dashboard'
import { CHART_HEIGHT, ChartEmpty, ChartSkeleton } from './ChartStates'
import { usePeakHours } from './useAnalytics'

/**
 * Peak check-in hours (Owner analytics slice). Branch-scoped — re-fetches
 * on BranchSwitcher change. Not a Recharts chart: a day-of-week × hour
 * grid is a plain CSS-grid heatmap, cell opacity scaled by count. Reads
 * whatever check-ins exist regardless of how they were recorded.
 */

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const HOURS = Array.from({ length: 24 }, (_, h) => h)
const HOUR_TICKS = new Set([0, 6, 12, 18])

export function PeakHoursHeatmap({ branchId }: { branchId?: string | null }) {
  const { data, loading, loaded } = usePeakHours(branchId, 30)

  const counts: Record<string, number> = {}
  for (const cell of data?.grid ?? []) {
    counts[`${cell.dayOfWeek}-${cell.hour}`] = cell.count
  }
  const maxCount = data?.maxCount ?? 0

  return (
    <ChartCard title="Peak check-in hours" action={<span className="font-mono text-xs text-ink-soft">last 30 days</span>}>
      {loading && !loaded ? (
        <ChartSkeleton />
      ) : (data?.totalCheckins ?? 0) === 0 ? (
        <ChartEmpty message="No check-ins recorded in the last 30 days." />
      ) : (
        <div className={`${CHART_HEIGHT} w-full overflow-x-auto`}>
          <div className="min-w-[520px]">
            <div className="grid gap-0.5" style={{ gridTemplateColumns: '2.5rem repeat(24, 1fr)' }}>
              {DAY_LABELS.map((label, day) => (
                <div key={label} className="contents">
                  <div className="flex items-center pr-1 font-mono text-[10px] text-ink-soft">{label}</div>
                  {HOURS.map((hour) => {
                    const count = counts[`${day}-${hour}`] ?? 0
                    // Guard maxCount === 0 (handled by the empty state above, but keep the math safe).
                    const intensity = maxCount > 0 ? count / maxCount : 0

                    return (
                      <div
                        key={hour}
                        title={`${label} ${hour}:00 — ${count} check-in${count === 1 ? '' : 's'}`}
                        className="aspect-square rounded-[2px] bg-ink"
                        style={{ opacity: count === 0 ? 0.06 : 0.15 + 0.85 * intensity }}
                      />
                    )
                  })}
                </div>
              ))}
              <div className="font-mono text-[10px] text-ink-soft" />
              {HOURS.map((hour) => (
                <div key={hour} className="pt-1 text-center font-mono text-[9px] text-ink-soft">
                  {HOUR_TICKS.has(hour) ? hour : ''}
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </ChartCard>
  )
}

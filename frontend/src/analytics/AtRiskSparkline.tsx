import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ChartCard } from '../components/dashboard'
import { ChartEmpty, ChartSkeleton } from './ChartStates'
import { SERIES, tooltipProps } from './chartTheme'
import { useAtRiskTrend } from './useAnalytics'

/**
 * At-risk members trend (Owner analytics slice). Hub-wide — ignores the
 * BranchSwitcher. One point per week for the trailing 12 weeks; the big
 * number is the most recent week. "At risk" = showing a retention signal
 * (no recent check-in, near expiry without renewal, frequency drop) —
 * the same rules the At-risk members tab lists per member.
 */

function formatWeek(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

export function AtRiskSparkline() {
  const { data, loading, loaded } = useAtRiskTrend(12)

  const points = (data?.trend ?? []).map((p) => ({ label: formatWeek(p.weekEnding), count: p.count }))
  const previous = points.length > 1 ? points[points.length - 2].count : null
  const current = data?.current ?? 0
  const delta = previous === null ? null : current - previous

  return (
    <ChartCard title="At-risk members" action={<span className="font-mono text-xs text-ink-soft">12-week trend</span>}>
      {loading && !loaded ? (
        <ChartSkeleton />
      ) : points.length === 0 ? (
        <ChartEmpty message="Not enough history yet to show a trend." />
      ) : (
        <div className="h-56 w-full">
          <div className="flex items-baseline gap-2">
            <span className="font-display text-4xl font-bold tabular-nums text-ink">{current}</span>
            {delta !== null && delta !== 0 ? (
              <span className={`font-mono text-xs ${delta > 0 ? 'text-member' : 'text-coach'}`}>
                {delta > 0 ? '▲' : '▼'} {Math.abs(delta)} vs last week
              </span>
            ) : (
              <span className="font-mono text-xs text-ink-soft">no change vs last week</span>
            )}
          </div>
          <div className="mt-3 h-40 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                <Tooltip {...tooltipProps} formatter={(value) => [Number(value), 'At risk'] as [number, string]} />
                <Line
                  type="monotone"
                  dataKey="count"
                  stroke={SERIES.member}
                  strokeWidth={2}
                  dot={{ r: 2, fill: SERIES.member }}
                  activeDot={{ r: 4 }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      )}
    </ChartCard>
  )
}

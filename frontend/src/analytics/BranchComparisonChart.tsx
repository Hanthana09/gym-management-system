import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChartCard } from '../components/dashboard'
import { CHART_HEIGHT, ChartEmpty, ChartSkeleton } from './ChartStates'
import { AXIS, axisTick, GRID, INK, tooltipProps } from './chartTheme'
import { useBranchComparison } from './useAnalytics'

/**
 * Branch comparison (Owner analytics slice). Hub-wide by definition — no
 * branch filter, ignores the BranchSwitcher. The parent only mounts this
 * for multi-branch gyms (a single-branch gym has nothing to compare).
 */

type Metric = 'revenue' | 'activeMembers' | 'attendanceCount'
const METRICS: { key: Metric; label: string }[] = [
  { key: 'revenue', label: 'Revenue' },
  { key: 'activeMembers', label: 'Members' },
  { key: 'attendanceCount', label: 'Check-ins' },
]
const currency = new Intl.NumberFormat([], { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })

export function BranchComparisonChart() {
  const [metric, setMetric] = useState<Metric>('revenue')
  const { data, loading, loaded } = useBranchComparison('30d')

  const rows = (data?.branches ?? []).map((b) => ({
    name: b.branchName,
    revenue: Number(b.revenue),
    activeMembers: b.activeMembers,
    attendanceCount: b.attendanceCount,
  }))

  return (
    <ChartCard
      title="Branch comparison"
      action={
        <select
          aria-label="Metric"
          value={metric}
          onChange={(e) => setMetric(e.target.value as Metric)}
          className="rounded-full border border-line bg-card px-2 py-1 font-mono text-xs uppercase tracking-wide text-ink"
        >
          {METRICS.map((m) => (
            <option key={m.key} value={m.key}>
              {m.label}
            </option>
          ))}
        </select>
      }
    >
      {loading && !loaded ? (
        <ChartSkeleton />
      ) : rows.length === 0 ? (
        <ChartEmpty message="No branch activity in the last 30 days yet." />
      ) : (
        <div className={`${CHART_HEIGHT} w-full`}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 12, bottom: 0, left: 8 }}>
              <CartesianGrid stroke={GRID} strokeDasharray="3 3" horizontal={false} />
              <XAxis
                type="number"
                tick={axisTick}
                stroke={AXIS}
                tickLine={false}
                tickFormatter={(v: number) => (metric === 'revenue' ? currency.format(v) : String(v))}
              />
              <YAxis type="category" dataKey="name" tick={axisTick} stroke={AXIS} tickLine={false} width={80} />
              <Tooltip
                {...tooltipProps}
                formatter={(value) => {
                  const v = Number(value)

                  return [metric === 'revenue' ? currency.format(v) : String(v), METRICS.find((m) => m.key === metric)?.label ?? ''] as [
                    string,
                    string,
                  ]
                }}
              />
              <Bar dataKey={metric} fill={INK} radius={[0, 3, 3, 0]} maxBarSize={28} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </ChartCard>
  )
}

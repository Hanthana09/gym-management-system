import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChartCard } from '../components/dashboard'
import { CHART_HEIGHT, ChartEmpty, ChartSkeleton } from './ChartStates'
import { AXIS, axisTick, GRID, SERIES, tooltipProps } from './chartTheme'
import { useNewVsReturning } from './useAnalytics'

/**
 * New vs returning members per month (Owner analytics slice). Hub-wide —
 * ignores the BranchSwitcher. "Returning" is derived, not a stored flag: a
 * membership start counts as returning when that member had an earlier
 * membership (see backend MembershipRepository::newVsReturningByMonth).
 */

function formatMonth(period: string): string {
  const [y, m] = period.split('-')

  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString([], { month: 'short', year: '2-digit' })
}

export function NewVsReturningChart() {
  const { data, loading, loaded } = useNewVsReturning()

  const rows = (data?.series ?? []).map((p) => ({ label: formatMonth(p.period), new: p.new, returning: p.returning }))
  const hasAny = rows.some((r) => r.new > 0 || r.returning > 0)

  return (
    <ChartCard title="New vs returning">
      {loading && !loaded ? (
        <ChartSkeleton />
      ) : !hasAny ? (
        <ChartEmpty message="No new or returning members in this period yet." />
      ) : (
        <div className={`${CHART_HEIGHT} w-full`}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
              <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
              <XAxis dataKey="label" tick={axisTick} stroke={AXIS} tickLine={false} minTickGap={16} />
              <YAxis tick={axisTick} stroke={AXIS} tickLine={false} width={32} allowDecimals={false} />
              <Tooltip {...tooltipProps} />
              <Legend wrapperStyle={{ fontSize: '0.75rem', color: 'var(--color-ink-soft)' }} />
              <Bar dataKey="new" name="New" stackId="m" fill={SERIES.owner} maxBarSize={36} />
              <Bar dataKey="returning" name="Returning" stackId="m" fill={SERIES.coach} maxBarSize={36} radius={[3, 3, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </ChartCard>
  )
}

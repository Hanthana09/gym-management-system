import { useState } from 'react'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChartCard } from '../components/dashboard'
import { CHART_HEIGHT, ChartEmpty, ChartSkeleton } from './ChartStates'
import { AXIS, axisTick, GRID, INK, tooltipProps } from './chartTheme'
import { useRevenueTrend } from './useAnalytics'
import type { RevenueGranularity } from './types'

function formatPeriod(period: string, granularity: RevenueGranularity): string {
  if (granularity === 'monthly') {
    const [y, m] = period.split('-')

    return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString([], { month: 'short', year: '2-digit' })
  }

  return new Date(period).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

const currency = new Intl.NumberFormat([], { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })

/**
 * Revenue trend (Owner analytics slice). Hub-wide by design — ignores the
 * BranchSwitcher. The Daily/Monthly toggle changes the hook's query string,
 * which triggers a fresh fetch (the monthly series is rolled up server-side,
 * not resliced from daily rows client-side — prompt task 6).
 */
export function RevenueTrendChart() {
  const [granularity, setGranularity] = useState<RevenueGranularity>('daily')
  const { data, loading, loaded } = useRevenueTrend(granularity)

  const points = (data?.series ?? []).map((p) => ({ label: formatPeriod(p.period, granularity), revenue: Number(p.revenue) }))

  return (
    <ChartCard
      title="Revenue trend"
      action={
        <div className="flex overflow-hidden rounded-full border border-line text-xs font-mono uppercase tracking-wide">
          {(['daily', 'monthly'] as const).map((g) => (
            <button
              key={g}
              type="button"
              onClick={() => setGranularity(g)}
              className={g === granularity ? 'bg-ink px-2.5 py-1 text-card' : 'px-2.5 py-1 text-ink-soft'}
            >
              {g === 'daily' ? 'Day' : 'Month'}
            </button>
          ))}
        </div>
      }
    >
      {loading && !loaded ? (
        <ChartSkeleton />
      ) : points.length === 0 ? (
        <ChartEmpty message="No revenue recorded in this period yet." />
      ) : (
        <div className={`${CHART_HEIGHT} w-full`}>
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
              <defs>
                <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={INK} stopOpacity={0.25} />
                  <stop offset="100%" stopColor={INK} stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
              <XAxis dataKey="label" tick={axisTick} stroke={AXIS} tickLine={false} minTickGap={24} />
              <YAxis
                tick={axisTick}
                stroke={AXIS}
                tickLine={false}
                width={48}
                tickFormatter={(v: number) => currency.format(v)}
              />
              <Tooltip {...tooltipProps} formatter={(value) => [currency.format(Number(value)), 'Revenue'] as [string, string]} />
              <Area type="monotone" dataKey="revenue" stroke={INK} strokeWidth={2} fill="url(#revenueFill)" />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}
    </ChartCard>
  )
}

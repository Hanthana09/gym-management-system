import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ChartCard } from '../components/dashboard'
import { CHART_HEIGHT, ChartEmpty, ChartSkeleton } from './ChartStates'
import { SERIES, tooltipProps } from './chartTheme'
import { useMembershipHealth } from './useAnalytics'
import type { MembershipHealthBucket } from './types'

/**
 * Membership health (Owner analytics slice). Branch-scoped — re-fetches on
 * BranchSwitcher change. There is no `frozen` status in this system; the
 * buckets are the ones that exist (see backend AnalyticsController note).
 */

const BUCKETS: { key: MembershipHealthBucket; label: string; color: string }[] = [
  { key: 'active', label: 'Active', color: SERIES.coach },
  { key: 'expiring', label: 'Expiring', color: SERIES.member },
  { key: 'expired', label: 'Expired', color: SERIES.primary },
  { key: 'paused', label: 'Paused', color: SERIES.soft },
  { key: 'suspended', label: 'Suspended', color: SERIES.owner },
  { key: 'cancelled', label: 'Cancelled', color: SERIES.line },
]

export function MembershipHealthChart({ branchId }: { branchId?: string | null }) {
  const { data, loading, loaded } = useMembershipHealth(branchId)

  const rows = BUCKETS.map((b) => ({ ...b, value: data?.buckets[b.key] ?? 0 })).filter((b) => b.value > 0)
  const total = rows.reduce((sum, b) => sum + b.value, 0)

  return (
    <ChartCard title="Membership health">
      {loading && !loaded ? (
        <ChartSkeleton />
      ) : total === 0 ? (
        <ChartEmpty message="No memberships to show for this branch yet." />
      ) : (
        <div className={`${CHART_HEIGHT} flex w-full items-center gap-4`}>
          <div className="h-full w-1/2">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={rows} dataKey="value" nameKey="label" innerRadius="55%" outerRadius="90%" strokeWidth={0}>
                  {rows.map((b) => (
                    <Cell key={b.key} fill={b.color} />
                  ))}
                </Pie>
                <Tooltip
                  {...tooltipProps}
                  formatter={(value, name) => {
                    const v = Number(value)

                    return [`${v} (${Math.round((v / total) * 100)}%)`, name] as [string, typeof name]
                  }}
                />
              </PieChart>
            </ResponsiveContainer>
          </div>
          <ul className="flex w-1/2 flex-col gap-1.5 text-sm">
            {rows.map((b) => (
              <li key={b.key} className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-2 text-ink-soft">
                  <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: b.color }} />
                  {b.label}
                </span>
                <span className="font-mono tabular-nums text-ink">{b.value}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </ChartCard>
  )
}

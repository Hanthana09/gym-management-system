import type { ReactNode } from 'react'
import { Card } from '../ui'

interface ChartCardProps {
  title: string
  action?: ReactNode
  children: ReactNode
}

/**
 * gym-management-dashboard-redesign.md Phase 2: a titled Card shell for
 * any chart/heatmap/progress-bar widget — role-agnostic, just wraps
 * whatever's passed as children. `action` is an optional small control
 * in the header (e.g. a horizon selector), matching the pattern already
 * used on OwnerDashboardPage's RevenueForecastCard.
 */
export function ChartCard({ title, action, children }: ChartCardProps) {
  return (
    <Card>
      <div className="mb-3 flex items-center justify-between gap-3">
        <h2 className="text-base font-semibold text-ink">{title}</h2>
        {action}
      </div>
      {children}
    </Card>
  )
}

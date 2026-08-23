import { Card } from '../ui'

interface KpiCardProps {
  label: string
  /** '—' is the caller's own choice for "not loaded yet" — this component doesn't invent a loading state. */
  value: string | number
  hint?: string
}

/**
 * gym-management-dashboard-redesign.md Phase 2: the shared KPI tile —
 * role-agnostic, takes only label/value/hint. Mobile-first: callers lay
 * these out in a `grid grid-cols-2 lg:grid-cols-3` (or similar) strip so
 * they reflow to 2 columns below desktop rather than shrinking illegibly
 * or scrolling horizontally.
 */
export function KpiCard({ label, value, hint }: KpiCardProps) {
  return (
    <Card>
      <p className="text-sm text-ink-soft">{label}</p>
      <p className="mt-1 font-display text-3xl font-bold text-ink tabular-nums sm:text-4xl">{value}</p>
      {hint ? <p className="mt-1 text-xs text-ink-soft">{hint}</p> : null}
    </Card>
  )
}

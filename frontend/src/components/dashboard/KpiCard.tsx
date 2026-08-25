import { Card } from '../ui'

interface KpiCardProps {
  label: string
  /** '—' is the caller's own choice for "not loaded yet" — this component doesn't invent a loading state. */
  value: string | number
  hint?: string
  /**
   * dataviz skill's stat-tile contract: an optional trailing sparkline,
   * oldest point first. Numeric strings (e.g. decimal revenue amounts)
   * are accepted directly — no caller-side parseFloat needed. Omitted
   * entirely (not just empty) by callers with no history to show
   * (CoachDashboardPage/StaffDashboardPage's counts).
   */
  trend?: (number | string)[]
}

/**
 * gym-management-dashboard-redesign.md Phase 2: the shared KPI tile —
 * role-agnostic, takes only label/value/hint(/trend). Mobile-first:
 * callers lay these out in a `grid grid-cols-2 lg:grid-cols-3` (or
 * similar) strip so they reflow to 2 columns below desktop rather than
 * shrinking illegibly or scrolling horizontally.
 */
export function KpiCard({ label, value, hint, trend }: KpiCardProps) {
  return (
    <Card>
      <p className="text-sm text-ink-soft">{label}</p>
      <p className="mt-1 font-display text-3xl font-bold text-ink tabular-nums sm:text-4xl">{value}</p>
      {hint ? <p className="mt-1 text-xs text-ink-soft">{hint}</p> : null}
      {trend && trend.length > 1 ? <Sparkline points={trend.map(Number)} /> : null}
    </Card>
  )
}

/**
 * dataviz skill mark spec: 2px line, rounded caps/joins, no axis/gridlines
 * (a sparkline carries no scale reading, only shape) — the trailing days
 * in the de-emphasis hue (`ink-soft`, this app's usual secondary-text
 * token), the current/last point lifted to the emphasis hue (`ink`, not
 * `hivis` — DESIGN-SYSTEM.md §4.1 reserves that for CTAs only). A flat
 * all-zero trend (nothing logged yet) still draws a flat mid-height line
 * rather than an empty box, so the tile never looks broken while
 * genuinely idle.
 */
function Sparkline({ points }: { points: number[] }) {
  const width = 100
  const height = 28
  const padX = 2
  const padY = 4
  const max = Math.max(...points, 0)
  const min = Math.min(...points, 0)
  const range = max - min || 1

  const coords = points.map((value, index) => {
    const x = padX + (index / (points.length - 1)) * (width - padX * 2)
    const y = height - padY - ((value - min) / range) * (height - padY * 2)

    return [x, y] as const
  })

  const linePath = coords.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const [lastX, lastY] = coords[coords.length - 1]
  const rangeLabel = `Last ${points.length} days: ${points.join(', ')}`

  return (
    <svg
      width="100%"
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className="mt-2"
      role="img"
      aria-label={rangeLabel}
    >
      <title>{rangeLabel}</title>
      <path
        d={linePath}
        fill="none"
        className="stroke-ink-soft"
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
      />
      <circle cx={lastX} cy={lastY} r={2.5} className="fill-ink" />
    </svg>
  )
}

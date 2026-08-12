import { cn } from '../lib/cn'
import type { RevenueForecastPointDto } from './types'

function formatShortDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

interface Point {
  x: number
  y: number
  date: string
  revenue: string
}

/**
 * functional requirements §10.3: "must never be presented as equivalent
 * to actuals" — historical is a solid line with filled points (ink),
 * projected is a dashed line with hollow points (ink-soft), plus a text
 * legend so the distinction doesn't rely on color/style alone. Same
 * hand-rolled-SVG approach as ProgressChart/AttendanceTrendChart.
 */
export function RevenueForecastChart({ historical, projected }: { historical: RevenueForecastPointDto[]; projected: RevenueForecastPointDto[] }) {
  const height = 160
  const padding = 24
  const all = [...historical, ...projected]
  const pointSpacing = all.length > 40 ? 12 : 24
  const width = Math.max(280, padding * 2 + (all.length - 1) * pointSpacing)
  const labelStride = Math.max(1, Math.ceil(all.length / 12))

  const values = all.map((e) => Number(e.revenue));
  const min = Math.min(0, ...values)
  const max = Math.max(...values, 1)
  const range = max - min || 1

  const xFor = (index: number) => (all.length === 1 ? width / 2 : padding + (index / (all.length - 1)) * (width - padding * 2))
  const yFor = (revenue: string) => height - padding - ((Number(revenue) - min) / range) * (height - padding * 2)

  const historicalPoints: Point[] = historical.map((entry, i) => ({ x: xFor(i), y: yFor(entry.revenue), date: entry.date, revenue: entry.revenue }))
  const projectedPoints: Point[] = projected.map((entry, i) => ({
    x: xFor(historical.length + i),
    y: yFor(entry.revenue),
    date: entry.date,
    revenue: entry.revenue,
  }))
  // The projected line starts from the last historical point so the two segments visually connect.
  const projectedPathPoints = historicalPoints.length > 0 ? [historicalPoints[historicalPoints.length - 1], ...projectedPoints] : projectedPoints

  const pathFor = (points: Point[]) => points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ')

  return (
    <div>
      <div className="mb-2 flex items-center gap-4 text-xs text-ink-soft">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-0.5 w-4 bg-ink" aria-hidden="true" />
          Actual
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-0.5 w-4 border-t-2 border-dashed border-ink-soft" aria-hidden="true" />
          Projected
        </span>
      </div>
      <div className="overflow-x-auto">
        <svg width={width} height={height + 28} role="img" aria-label="Revenue history and forecast chart">
          <path d={pathFor(historicalPoints)} fill="none" className="stroke-ink" strokeWidth={2} />
          <path d={pathFor(projectedPathPoints)} fill="none" className="stroke-ink-soft" strokeWidth={2} strokeDasharray="5 4" />
          {historicalPoints.map((point, index) => (
            <circle key={`h-${index}`} cx={point.x} cy={point.y} r={4} className="fill-ink" />
          ))}
          {projectedPoints.map((point, index) => (
            <circle key={`p-${index}`} cx={point.x} cy={point.y} r={4} className="fill-card stroke-ink-soft" strokeWidth={2} />
          ))}
          {all.map((entry, index) => {
            // Key markers (start, historical/projected boundary, end) always
            // show, even on mobile. Everything else is thinned to a fixed
            // stride so labels never overlap regardless of how many days
            // are in view (a 90-day forecast has ~120 points total) — "show
            // all at md:+" only works up to a handful of points.
            const isKeyMarker = index === 0 || index === all.length - 1 || index === historical.length - 1
            const isOnStride = index % labelStride === 0
            if (!isKeyMarker && !isOnStride) {
              return null
            }
            const x = xFor(index)

            return (
              <text
                key={entry.date}
                x={x}
                y={height + 18}
                textAnchor="middle"
                className={cn('fill-ink-soft font-mono text-[10px]', !isKeyMarker && 'hidden md:inline')}
              >
                {formatShortDate(entry.date)}
              </text>
            )
          })}
        </svg>
      </div>
    </div>
  )
}

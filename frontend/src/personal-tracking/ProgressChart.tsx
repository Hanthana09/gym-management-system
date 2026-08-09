import { cn } from '../lib/cn'
import type { BodyMetricDto } from './types'

function formatShortDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

/**
 * roadmap Phase 8: "simplified/scrollable on mobile (fewer axis labels,
 * larger touch-friendly data points), full detail at md:+." Hand-rolled
 * SVG rather than a charting library — Phase 1's primitive set has no
 * chart component, and a single trend line is simple enough that a
 * dependency would cost more (fighting its API for the mobile/md:+
 * split) than it saves. Data points stay the same touch-friendly size at
 * every breakpoint; "simplified on mobile" is expressed by showing only
 * the first/last date labels below `md:`, every point's date at `md:`+,
 * and an always-available horizontal scroll so dense histories never
 * compress into unreadable clutter.
 */
export function ProgressChart({ data }: { data: BodyMetricDto[] }) {
  const height = 180
  const padding = 24
  const pointSpacing = 56
  const width = Math.max(280, padding * 2 + (data.length - 1) * pointSpacing)

  const weights = data.map((entry) => Number(entry.weightKg))
  const min = Math.min(...weights)
  const max = Math.max(...weights)
  const range = max - min || 1

  const points = data.map((entry, index) => ({
    x: data.length === 1 ? width / 2 : padding + (index / (data.length - 1)) * (width - padding * 2),
    y: height - padding - ((Number(entry.weightKg) - min) / range) * (height - padding * 2),
    date: entry.date,
    weightKg: entry.weightKg,
  }))

  const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(' ')

  return (
    <div className="overflow-x-auto">
      <svg width={width} height={height + 28} role="img" aria-label="Weight trend chart">
        <path d={path} fill="none" className="stroke-ink" strokeWidth={2} />
        {points.map((point, index) => (
          <circle key={index} cx={point.x} cy={point.y} r={6} className="fill-ink" />
        ))}
        {points.map((point, index) => {
          const isEndpoint = index === 0 || index === points.length - 1

          return (
            <text
              key={index}
              x={point.x}
              y={height + 18}
              textAnchor="middle"
              className={cn('fill-ink-soft font-mono text-[10px]', !isEndpoint && 'hidden md:inline')}
            >
              {formatShortDate(point.date)}
            </text>
          )
        })}
      </svg>
    </div>
  )
}

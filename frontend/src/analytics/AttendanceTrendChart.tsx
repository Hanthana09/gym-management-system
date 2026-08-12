import { cn } from '../lib/cn'
import type { DailyCheckinCountDto } from '../attendance/types'

function formatShortDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

/**
 * functional requirements §10.2: "check-in counts per day... as a chart,
 * not just a single total." Hand-rolled SVG bar chart, same reasoning as
 * ProgressChart (personal-tracking) — no charting library, simplified
 * date labels below md:, horizontal scroll for dense ranges.
 */
export function AttendanceTrendChart({ data }: { data: DailyCheckinCountDto[] }) {
  const height = 140
  const padding = 20
  const barSpacing = 40
  const width = Math.max(280, padding * 2 + data.length * barSpacing)
  const barWidth = Math.min(28, barSpacing - 12)

  const max = Math.max(1, ...data.map((d) => d.count))
  // Endpoints always label, even on mobile; everything else is thinned to
  // a fixed stride so labels never overlap on a wide date range — "show
  // all at md:+" only holds up to a handful of bars.
  const labelStride = Math.max(1, Math.ceil(data.length / 18))

  return (
    <div className="overflow-x-auto">
      <svg width={width} height={height + 28} role="img" aria-label="Attendance trend chart">
        {data.map((entry, index) => {
          const barHeight = (entry.count / max) * (height - padding * 2)
          const x = padding + index * barSpacing + (barSpacing - barWidth) / 2
          const y = height - padding - barHeight
          const isEndpoint = index === 0 || index === data.length - 1
          const isOnStride = index % labelStride === 0

          return (
            <g key={entry.date}>
              <rect x={x} y={y} width={barWidth} height={Math.max(barHeight, 1)} rx={2} className="fill-ink" />
              {entry.count > 0 ? (
                <text x={x + barWidth / 2} y={y - 4} textAnchor="middle" className="fill-ink-soft font-mono text-[10px]">
                  {entry.count}
                </text>
              ) : null}
              {isEndpoint || isOnStride ? (
                <text
                  x={x + barWidth / 2}
                  y={height + 18}
                  textAnchor="middle"
                  className={cn('fill-ink-soft font-mono text-[10px]', !isEndpoint && 'hidden md:inline')}
                >
                  {formatShortDate(entry.date)}
                </text>
              ) : null}
            </g>
          )
        })}
      </svg>
    </div>
  )
}

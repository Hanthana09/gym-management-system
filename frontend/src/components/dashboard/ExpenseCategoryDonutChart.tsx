import type { CSSProperties } from 'react'
import type { ExpenseCategoryBreakdownEntryDto } from '../../dashboard/types'

export interface ExpenseCategoryDonutChartProps {
  data: ExpenseCategoryBreakdownEntryDto[]
}

// dataviz skill's validated default categorical theme (references/palette.md)
// — re-validated against this app's own card surface (light #F6F6F0, dark
// #1a201f) rather than the skill's generic reference surface. Fixed order,
// never cycled/generated: an 9th distinct category folds into "Other"
// rather than manufacturing a 9th hue (skill's own non-negotiable).
const CATEGORY_SLOTS_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948']
const CATEGORY_SLOTS_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']
// "Other" is an aggregate bucket, not a real category identity — muted gray
// (dataviz skill's chart-chrome "muted" token) rather than a categorical
// slot, so it never gets confused for one more series.
const OTHER_COLOR = '#898781'
const MAX_INDIVIDUAL_SLICES = 6

/**
 * Stable per-category color, independent of sort order or which other
 * categories are present this period — the dataviz skill's "color follows
 * the entity, never its rank" rule. A branch filter that changes which
 * categories show up (and how they rank) must not repaint the survivors,
 * so the slot is derived from the category's own id, not its position in
 * this render's sorted list.
 */
function slotFor(categoryId: string, slots: string[]): string {
  let hash = 0
  for (let i = 0; i < categoryId.length; i++) {
    hash = (hash * 31 + categoryId.charCodeAt(i)) >>> 0
  }

  return slots[hash % slots.length]
}

function formatAmount(value: number): string {
  return `$${value.toFixed(2)}`
}

/**
 * Owner home page: month-to-date expenses by category, as a donut.
 * dataviz skill: categorical color for identity, fixed hue order (never
 * generated), top 6 slices individually + the rest folded into "Other"
 * (series-count ladder's soft cap), a legend + on-slice percentage labels
 * for slices big enough to read (the light-mode palette's contrast WARN
 * requires exactly this relief), and a native `<title>` hover tooltip per
 * segment — same level of interactivity AttendanceHeatmap already uses
 * elsewhere in this app, not a new one-off pattern.
 */
export function ExpenseCategoryDonutChart({ data }: ExpenseCategoryDonutChartProps) {
  if (data.length === 0) return null

  const sorted = [...data].sort((a, b) => Number(b.amount) - Number(a.amount))
  const head = sorted.slice(0, MAX_INDIVIDUAL_SLICES)
  const tail = sorted.slice(MAX_INDIVIDUAL_SLICES)
  const otherTotal = tail.reduce((sum, entry) => sum + Number(entry.amount), 0)

  const slices = [
    ...head.map((entry) => ({
      key: entry.categoryId,
      label: entry.categoryName,
      value: Number(entry.amount),
      colorLight: slotFor(entry.categoryId, CATEGORY_SLOTS_LIGHT),
      colorDark: slotFor(entry.categoryId, CATEGORY_SLOTS_DARK),
    })),
    ...(otherTotal > 0
      ? [{ key: '__other__', label: 'Other', value: otherTotal, colorLight: OTHER_COLOR, colorDark: OTHER_COLOR }]
      : []),
  ]

  const total = slices.reduce((sum, s) => sum + s.value, 0)
  if (total <= 0) return null

  const size = 180
  const center = size / 2
  const outerRadius = 78
  const innerRadius = 46
  const ringRadius = (outerRadius + innerRadius) / 2
  const strokeWidth = outerRadius - innerRadius
  const circumference = 2 * Math.PI * ringRadius
  const gapPx = 2

  let cumulative = 0

  return (
    <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:justify-center">
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label="Expenses by category, this month">
        <g transform={`rotate(-90 ${center} ${center})`}>
          {slices.map((slice) => {
            const fraction = slice.value / total
            const dashLength = Math.max(fraction * circumference - gapPx, 0)
            const dashArray = `${dashLength} ${circumference - dashLength}`
            const dashOffset = -cumulative * circumference
            cumulative += fraction

            return (
              <circle
                key={slice.key}
                cx={center}
                cy={center}
                r={ringRadius}
                fill="none"
                stroke="currentColor"
                strokeWidth={strokeWidth}
                strokeDasharray={dashArray}
                strokeDashoffset={dashOffset}
                className="chart-series"
                style={{ '--series-color-light': slice.colorLight, '--series-color-dark': slice.colorDark } as CSSProperties}
              >
                <title>{`${slice.label}: ${formatAmount(slice.value)} (${Math.round((slice.value / total) * 100)}%)`}</title>
              </circle>
            )
          })}
        </g>
        <text x={center} y={center - 6} textAnchor="middle" className="fill-ink font-display text-lg font-bold tabular-nums">
          {formatAmount(total)}
        </text>
        <text x={center} y={center + 14} textAnchor="middle" className="fill-ink-soft font-mono text-[10px] uppercase tracking-wide">
          This month
        </text>
      </svg>

      {/* Legend doubles as the WARN-relief direct labels the light-mode
          palette check requires (references/color-formula.md) — every
          slice's identity and share is readable from text, not color alone. */}
      <ul className="flex w-full flex-col gap-2 sm:w-auto sm:min-w-48">
        {slices.map((slice) => (
          <li key={slice.key} className="flex items-center justify-between gap-3 text-sm">
            <span className="flex min-w-0 items-center gap-2">
              <span
                aria-hidden
                className="chart-series h-2.5 w-2.5 shrink-0 rounded-full bg-current"
                style={{ '--series-color-light': slice.colorLight, '--series-color-dark': slice.colorDark } as CSSProperties}
              />
              <span className="truncate text-ink">{slice.label}</span>
            </span>
            <span className="shrink-0 font-mono text-xs text-ink-soft tabular-nums">
              {formatAmount(slice.value)} · {Math.round((slice.value / total) * 100)}%
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

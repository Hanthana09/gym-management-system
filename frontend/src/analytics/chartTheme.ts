/**
 * Recharts styling shared across the home-dashboard chart widgets.
 *
 * Colors are the project's own design tokens (src/index.css `@theme`),
 * referenced as `var(--color-*)` so charts follow light/dark automatically
 * with no `dark:` variants — the same mechanism DESIGN-SYSTEM.md §1 relies
 * on everywhere else. `hivis` is deliberately not used as a series color:
 * it is reserved for actions (DESIGN-SYSTEM.md §4.1), not data.
 */

export const AXIS = 'var(--color-ink-soft)'
export const GRID = 'var(--color-line)'
export const INK = 'var(--color-ink)'

/** Categorical palette for status / segment charts — existing tokens only. */
export const SERIES = {
  primary: 'var(--color-ink)',
  owner: 'var(--color-owner)',
  coach: 'var(--color-coach)',
  member: 'var(--color-member)',
  soft: 'var(--color-ink-soft)',
  line: 'var(--color-line)',
} as const

export const tooltipProps = {
  contentStyle: {
    background: 'var(--color-card)',
    border: '1px solid var(--color-line)',
    borderRadius: '0.5rem',
    fontSize: '0.75rem',
    color: 'var(--color-ink)',
    boxShadow: 'none',
  },
  labelStyle: { color: 'var(--color-ink-soft)', fontFamily: 'IBM Plex Mono, ui-monospace, monospace' },
  itemStyle: { color: 'var(--color-ink)' },
  cursor: { fill: 'var(--color-paper-dim)', stroke: 'var(--color-line)' },
} as const

export const axisTick = { fill: AXIS, fontSize: 11, fontFamily: 'IBM Plex Mono, ui-monospace, monospace' } as const

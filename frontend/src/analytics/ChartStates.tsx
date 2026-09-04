/**
 * Shared loading / empty states for the home-dashboard chart widgets
 * (Owner analytics slice). Keeps every widget's skeleton the same height
 * as its rendered chart so the dashboard grid doesn't reflow when data
 * arrives, and gives empty branches a plain sentence rather than an empty
 * chart frame (prompt task 4).
 */

/** Matches the chart area height used by every widget below. */
export const CHART_HEIGHT = 'h-56'

export function ChartSkeleton() {
  return <div className={`${CHART_HEIGHT} w-full animate-pulse rounded-lg bg-paper-dim`} aria-hidden="true" />
}

export function ChartEmpty({ message }: { message: string }) {
  return (
    <div className={`${CHART_HEIGHT} flex w-full items-center justify-center px-4 text-center text-sm text-ink-soft`}>
      {message}
    </div>
  )
}

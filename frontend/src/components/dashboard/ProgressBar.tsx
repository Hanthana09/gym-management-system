interface ProgressBarProps {
  /** 0-100. Values outside this range are clamped, never rendered raw. */
  percentage: number
  label?: string
}

/**
 * gym-management-dashboard-redesign.md Phase 2: a horizontal progress/
 * utilization bar (the spec's "BranchBar/ProgressBar") — role-agnostic,
 * just a percentage + optional label. Used for Coach's weekly
 * utilization bar; equally reusable anywhere else a single 0-100 value
 * needs a visual bar instead of a bare number.
 */
export function ProgressBar({ percentage, label }: ProgressBarProps) {
  const clamped = Math.max(0, Math.min(100, percentage))

  return (
    <div>
      {label ? <p className="mb-1.5 text-sm text-ink-soft">{label}</p> : null}
      <div className="h-2.5 w-full overflow-hidden rounded-full bg-paper-dim" role="progressbar" aria-valuenow={clamped} aria-valuemin={0} aria-valuemax={100}>
        <div className="h-full rounded-full bg-hivis" style={{ width: `${clamped}%` }} />
      </div>
    </div>
  )
}

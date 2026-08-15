import { useAuth } from '../auth/AuthContext'
import { cn } from '../lib/cn'
import { useActiveAttendance } from './useActiveAttendance'
import { useElapsedTime } from './useElapsedTime'
import { formatElapsed } from './formatElapsed'

/**
 * Member top bar only — "today's active session" timer, live-synced via
 * Mercure so a checkout from another device/tab freezes it within a few
 * seconds. Renders nothing at all when there's no open session (not a
 * disabled/empty state) — same "genuinely absent, not hidden" rule this
 * app already uses for BranchSwitcher. Out of scope by design: no
 * historical session list, no auto-checkout, no Owner/Coach top bar.
 */
export function CheckInTimer() {
  const { user } = useAuth()
  const { attendance } = useActiveAttendance(user?.id ?? null)
  const elapsedMs = useElapsedTime(attendance?.checkInAt ?? null, attendance?.checkOutAt ?? null)

  if (attendance === null) return null

  const ended = attendance.checkOutAt !== null

  return (
    <div className="flex items-center gap-1.5 font-mono text-sm text-ink tabular-nums" aria-live="polite">
      {!ended ? (
        <span className="relative flex h-2 w-2 shrink-0" aria-hidden="true">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-hivis opacity-75" />
          <span className="relative inline-flex h-2 w-2 rounded-full bg-hivis" />
        </span>
      ) : null}
      <span className={cn(ended ? 'text-ink' : 'text-member')}>{formatElapsed(elapsedMs)}</span>
    </div>
  )
}

import { NavShell } from '../components/NavShell'
import { MEMBER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card } from '../components/ui'
import { CheckInIcon } from '../components/ui/icons'
import { useCheckIn } from '../attendance/useCheckIn'
import { useElapsedTime } from '../attendance/useElapsedTime'
import { formatElapsed } from '../attendance/formatElapsed'
import { useAuth } from '../auth/AuthContext'
import { useMilestoneWatcher } from '../milestones/useMilestoneWatcher'
import { MilestoneCelebrationModal } from '../milestones/MilestoneCelebrationModal'

const BLOCKED_REASON_LABELS: Record<string, string> = {
  membership_expired: 'Membership expired',
  membership_paused: 'Membership paused',
  membership_cancelled: 'Membership cancelled',
  account_suspended: 'Account suspended',
  no_membership: 'No membership',
  // gym-management-billing-v1.md §5.5/§7 — plain-language check-in-blocked reasons for billing gating.
  subscription_inactive: 'Membership suspended',
  absent_invoice: 'Payment missed',
  overdue: 'Payment overdue',
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

/**
 * "The highest-priority mobile screen in the whole system" (roadmap
 * Phase 5): one tap, no scrolling, large single button. Reachable from
 * the bottom nav's first tab (Phase 1's MEMBER_NAV_ITEMS[0]).
 *
 * One button, two meanings: label and action follow useCheckIn's
 * isCheckedIn (same ground truth the top-bar CheckInTimer reads) — "Check
 * In" while there's no open session, "Check Out" while there is. The
 * elapsed-time card mirrors the top bar's clock but larger, and freezes
 * the same way: once checkOutAt is set, useElapsedTime stops ticking.
 */
export function MemberCheckInPage() {
  const { user } = useAuth()
  const { attendance, loaded, isCheckedIn, phase, error, toggle, dismissError } = useCheckIn(user?.id ?? null)
  const { milestone, dismiss } = useMilestoneWatcher()
  const elapsedMs = useElapsedTime(attendance?.checkInAt ?? null, attendance?.checkOutAt ?? null)

  return (
    <div className="h-dvh">
      <NavShell role="member" title="Gym" navItems={MEMBER_NAV_ITEMS} activeHref="/member/check-in">
        {milestone && user ? (
          <MilestoneCelebrationModal memberName={user.name} streakDays={milestone.streakDays} onClose={dismiss} />
        ) : null}
        <div className="flex h-full flex-col items-center justify-center gap-6">
          {error?.kind === 'blocked' ? (
            <Card className="w-full max-w-sm text-center">
              <p className="text-lg font-semibold text-red-700">
                {BLOCKED_REASON_LABELS[error.reason] ?? 'Check-in blocked'}
              </p>
              <p className="mt-1 text-sm text-ink-soft">{error.message}</p>
              <Button className="mt-4" variant="secondary" onClick={dismissError}>
                Back
              </Button>
            </Card>
          ) : error?.kind === 'error' ? (
            <Card className="w-full max-w-sm text-center">
              <p className="text-lg font-semibold text-ink">Something went wrong</p>
              <p className="mt-1 text-sm text-ink-soft">
                We couldn't reach the server. Check your connection and try again.
              </p>
              <Button className="mt-4" fullWidth onClick={dismissError}>
                Retry
              </Button>
            </Card>
          ) : (
            <>
              <button
                type="button"
                onClick={toggle}
                disabled={phase === 'working' || !loaded}
                aria-label={isCheckedIn ? 'Check out' : 'Check in'}
                className="flex h-56 w-56 flex-col items-center justify-center gap-2 rounded-full bg-hivis text-ink shadow-lg transition-colors hover:bg-hivis/90 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-ink focus-visible:ring-offset-2 disabled:opacity-60"
              >
                <CheckInIcon className="h-12 w-12" />
                <span className="text-xl font-semibold">
                  {phase === 'working'
                    ? isCheckedIn
                      ? 'Checking out…'
                      : 'Checking in…'
                    : isCheckedIn
                      ? 'Check Out'
                      : 'Check In'}
                </span>
              </button>

              {attendance !== null ? (
                <Card className="w-full max-w-sm text-center">
                  <p className={`font-display text-3xl font-bold tabular-nums ${isCheckedIn ? 'text-member' : 'text-ink'}`}>
                    {formatElapsed(elapsedMs)}
                  </p>
                  <p className="mt-1 text-sm text-ink-soft">
                    {isCheckedIn
                      ? `Checked in at ${formatTime(attendance.checkInAt)}`
                      : `Checked out at ${formatTime(attendance.checkOutAt as string)}`}
                  </p>
                </Card>
              ) : null}
            </>
          )}
        </div>
      </NavShell>
    </div>
  )
}

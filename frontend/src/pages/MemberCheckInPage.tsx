import { NavShell } from '../components/NavShell'
import { MEMBER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card } from '../components/ui'
import { CheckInIcon } from '../components/ui/icons'
import { useCheckIn } from '../attendance/useCheckIn'

const BLOCKED_REASON_LABELS: Record<string, string> = {
  membership_expired: 'Membership expired',
  membership_paused: 'Membership paused',
  membership_cancelled: 'Membership cancelled',
  account_suspended: 'Account suspended',
  no_membership: 'No membership',
}

/**
 * "The highest-priority mobile screen in the whole system" (roadmap
 * Phase 5): one tap, no scrolling, large single button. Reachable from
 * the bottom nav's first tab (Phase 1's MEMBER_NAV_ITEMS[0]).
 */
export function MemberCheckInPage() {
  const { state, checkIn, reset } = useCheckIn()

  return (
    <div className="h-dvh">
      <NavShell role="member" title="Gym" navItems={MEMBER_NAV_ITEMS} activeHref="/member/check-in">
        <div className="flex h-full flex-col items-center justify-center gap-6">
          {state.status === 'success' ? (
            <Card className="w-full max-w-sm text-center">
              <p className="text-lg font-semibold text-green-700">You're checked in!</p>
              <p className="mt-1 text-sm text-ink-soft">
                {new Date(state.checkInAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
              </p>
            </Card>
          ) : state.status === 'blocked' ? (
            <Card className="w-full max-w-sm text-center">
              <p className="text-lg font-semibold text-red-700">
                {BLOCKED_REASON_LABELS[state.reason] ?? 'Check-in blocked'}
              </p>
              <p className="mt-1 text-sm text-ink-soft">{state.message}</p>
            </Card>
          ) : state.status === 'error' ? (
            <Card className="w-full max-w-sm text-center">
              <p className="text-lg font-semibold text-ink">Couldn't check in</p>
              <p className="mt-1 text-sm text-ink-soft">
                We couldn't reach the server. Check your connection and try again.
              </p>
              <Button className="mt-4" fullWidth onClick={checkIn}>
                Retry
              </Button>
            </Card>
          ) : (
            <button
              type="button"
              onClick={checkIn}
              disabled={state.status === 'checking'}
              aria-label="Check in"
              className="flex h-56 w-56 flex-col items-center justify-center gap-2 rounded-full bg-hivis text-ink shadow-lg transition-colors hover:bg-hivis/90 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-ink focus-visible:ring-offset-2 disabled:opacity-60"
            >
              <CheckInIcon className="h-12 w-12" />
              <span className="text-xl font-semibold">
                {state.status === 'checking' ? 'Checking in…' : 'Check In'}
              </span>
            </button>
          )}

          {(state.status === 'success' || state.status === 'blocked') && (
            <Button variant="secondary" onClick={reset}>
              Back
            </Button>
          )}
        </div>
      </NavShell>
    </div>
  )
}

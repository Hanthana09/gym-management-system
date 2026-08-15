import { useCallback, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { useActiveAttendance } from './useActiveAttendance'

export type CheckInActionPhase = 'idle' | 'working'

export type CheckInActionError =
  | { kind: 'blocked'; reason: string; message: string }
  /** Anything that isn't a known blocked-status response — real network
   * failures, timeouts, unexpected server errors. Functional requirements
   * §4.1: never a silent failure or an ambiguous spinner, always a clear
   * retry action. */
  | { kind: 'error' }

/**
 * Drives MemberCheckInPage's single toggle button. "Checked in" ground
 * truth comes from useActiveAttendance — the same source the top-bar
 * CheckInTimer reads — so the button and the clock can never disagree,
 * and a checkout triggered elsewhere (another device, via Mercure) flips
 * this button back to "Check In" too, not just the top-bar clock.
 *
 * After a successful mutation this applies that mutation's own response
 * directly (applyUpdate) rather than re-fetching GET /attendance/active —
 * that endpoint only returns the currently OPEN session, so re-fetching
 * right after a checkout would (correctly, for a fresh page load) come
 * back null and erase the just-frozen clock instead of showing it frozen.
 * Every other tab/device still gets the update via the Mercure
 * subscription useActiveAttendance already holds open.
 */
export function useCheckIn(userId: string | null) {
  const { authFetch } = useAuth()
  const { attendance, loaded, refresh, applyUpdate } = useActiveAttendance(userId)
  const [phase, setPhase] = useState<CheckInActionPhase>('idle')
  const [error, setError] = useState<CheckInActionError | null>(null)

  const isCheckedIn = attendance !== null && attendance.checkOutAt === null

  const checkIn = useCallback(async () => {
    setPhase('working')
    setError(null)
    try {
      const result = await authFetch<{ checkInAt: string }>('/members/me/checkin', {})
      applyUpdate({ checkInAt: result.checkInAt, checkOutAt: null })
    } catch (err) {
      if (err instanceof ApiError && err.code === 'checkin_blocked' && err.reason) {
        setError({ kind: 'blocked', reason: err.reason, message: err.message })
      } else {
        setError({ kind: 'error' })
      }
    } finally {
      setPhase('idle')
    }
  }, [authFetch, applyUpdate])

  const checkOut = useCallback(async () => {
    setPhase('working')
    setError(null)
    try {
      const result = await authFetch<{ checkInAt: string; checkOutAt: string }>('/members/me/checkout', {})
      applyUpdate({ checkInAt: result.checkInAt, checkOutAt: result.checkOutAt })
    } catch {
      // A 409 here most plausibly means the session was already closed
      // elsewhere between this page loading and this button press —
      // resync from the server so the button doesn't get stuck offering
      // "Check Out" for a session that's already gone.
      await refresh()
      setError({ kind: 'error' })
    } finally {
      setPhase('idle')
    }
  }, [authFetch, applyUpdate, refresh])

  const toggle = useCallback(() => (isCheckedIn ? checkOut() : checkIn()), [isCheckedIn, checkOut, checkIn])

  const dismissError = useCallback(() => setError(null), [])

  return { attendance, loaded, isCheckedIn, phase, error, toggle, dismissError }
}

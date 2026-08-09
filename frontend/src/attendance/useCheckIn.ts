import { useCallback, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'

interface CheckInResponse {
  id: string
  checkInAt: string
  method: string
}

export type CheckInState =
  | { status: 'idle' }
  | { status: 'checking' }
  | { status: 'success'; checkInAt: string }
  | { status: 'blocked'; reason: string; message: string }
  /** Anything that isn't a known blocked-status response — real network
   * failures, timeouts, unexpected server errors. Functional requirements
   * §4.1: never a silent failure or an ambiguous spinner, always a clear
   * retry action. */
  | { status: 'error' }

export function useCheckIn() {
  const { authFetch } = useAuth()
  const [state, setState] = useState<CheckInState>({ status: 'idle' })

  const checkIn = useCallback(async () => {
    setState({ status: 'checking' })

    try {
      const result = await authFetch<CheckInResponse>('/members/me/checkin', {})
      setState({ status: 'success', checkInAt: result.checkInAt })
    } catch (err) {
      if (err instanceof ApiError && err.code === 'checkin_blocked' && err.reason) {
        setState({ status: 'blocked', reason: err.reason, message: err.message })
      } else {
        setState({ status: 'error' })
      }
    }
  }, [authFetch])

  const reset = useCallback(() => setState({ status: 'idle' }), [])

  return { state, checkIn, reset }
}

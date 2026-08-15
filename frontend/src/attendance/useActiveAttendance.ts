import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { ActiveAttendanceDto } from './types'

/**
 * Check-in-timer feature. Seeds from GET /members/:id/attendance/active —
 * refresh-safe, since it's recomputed server-side from the open
 * AttendanceLog row, never trusted from client-held state — then a
 * Mercure subscription to `attendance/{userId}` keeps it live: a checkout
 * from another device/tab (or nothing recorded here at all) freezes/
 * updates this without a manual refresh, same non-private-topic pattern
 * as every other Mercure feature in this app (see
 * AttendanceMercurePublisher's own docblock on why there's no
 * subscriber-JWT ACL here — the real 403 is on the GET endpoint above).
 */
export function useActiveAttendance(userId: string | null) {
  const { authFetch } = useAuth()
  const [attendance, setAttendance] = useState<ActiveAttendanceDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    if (userId === null) return

    const data = await authFetch<{ attendance: ActiveAttendanceDto | null }>(`/members/${userId}/attendance/active`, {
      method: 'GET',
    })
    setAttendance(data.attendance)
    setLoaded(true)
  }, [authFetch, userId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (userId === null) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `attendance/${userId}`)
    const source = new EventSource(url)

    source.onmessage = (event) => {
      const update = JSON.parse(event.data) as { checkInTime: string; checkOutTime: string | null }
      setAttendance({ checkInAt: update.checkInTime, checkOutAt: update.checkOutTime })
    }

    return () => source.close()
  }, [userId])

  /**
   * For the actor who just performed the mutation: apply the check-in/
   * check-out response's own data directly, rather than re-fetching GET
   * /attendance/active — that endpoint only ever returns the currently
   * OPEN session, so calling it right after a checkout would (correctly,
   * for a fresh page load) come back null, erasing the just-frozen clock
   * instead of showing it. Every other tab/device still gets this same
   * update via the Mercure subscription above.
   */
  const applyUpdate = useCallback((next: ActiveAttendanceDto | null) => {
    setAttendance(next)
  }, [])

  return { attendance, loaded, refresh, applyUpdate }
}

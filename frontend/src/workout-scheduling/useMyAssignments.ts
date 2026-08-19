import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { WorkoutAssignmentDto } from './types'

/**
 * setly-phase-workout-scheduling.md §7/§6: Member's own active assignment(s)
 * + Mercure subscription on `members/{id}/assignment-updates`. On message,
 * this just re-calls refresh() — no query-cache layer exists in this
 * codebase (see useCoachSchedule.ts for the identical pattern), so
 * "invalidate the cache" is "refetch".
 */
export function useMyAssignments() {
  const { authFetch, user } = useAuth()
  const [assignments, setAssignments] = useState<WorkoutAssignmentDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ assignments: WorkoutAssignmentDto[] }>('/workout-assignments?member=me&status=active', { method: 'GET' })
    setAssignments(data.assignments)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (!user) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `members/${user.id}/assignment-updates`)
    const source = new EventSource(url)
    source.onmessage = () => {
      void refresh()
    }

    return () => source.close()
  }, [user, refresh])

  return { assignments, loaded, refresh }
}

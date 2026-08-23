import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import type { CoachDashboardDto } from './types'

/**
 * Same required-if-multi-branch shape as useStaffDashboard — see that
 * hook's docblock, including the same stale-response guard: `branchId`
 * genuinely changes twice in quick succession on first mount for a
 * multi-branch Coach (null while useBranches() is still loading, then
 * the real default once it resolves) — without the guard, the first
 * (failing, "branch required") request can resolve *after* the second
 * (succeeding) one and clobber a correct result with a stale error.
 */
export function useCoachDashboard(branchId: string | null) {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<CoachDashboardDto | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const requestIdRef = useRef(0)

  const refresh = useCallback(async () => {
    const requestId = ++requestIdRef.current
    setLoaded(false)
    setError(null)
    try {
      const params = branchId ? `?branch=${branchId}` : ''
      const data = await authFetch<CoachDashboardDto>(`/v1/dashboard/coach${params}`, { method: 'GET' })
      if (requestId !== requestIdRef.current) return
      setSummary(data)
    } catch (err) {
      if (requestId !== requestIdRef.current) return
      setSummary(null)
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      if (requestId === requestIdRef.current) setLoaded(true)
    }
  }, [authFetch, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { summary, loaded, error, refresh }
}

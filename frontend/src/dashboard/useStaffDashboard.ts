import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import type { StaffDashboardDto } from './types'

/**
 * gym-management-dashboard-redesign.md Phase 3: `branchId` is required
 * once a Staff member has 2+ assigned branches — `null` is only ever
 * valid for a single-branch Staff member (the backend defaults it in
 * that case). `error` surfaces the backend's 400 ("branch is required")
 * so the page can prompt for a selection instead of showing a blank
 * dashboard.
 *
 * Stale-response guard: `branchId` genuinely changes twice in quick
 * succession on first mount for a multi-branch Staff member (null while
 * useBranches() is still loading, then the real default once it
 * resolves) — without tracking which request is the latest, the first
 * (failing, "branch required") request can resolve *after* the second
 * (succeeding) one and clobber a correct result with a stale error.
 */
export function useStaffDashboard(branchId: string | null) {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<StaffDashboardDto | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const requestIdRef = useRef(0)

  const refresh = useCallback(async () => {
    const requestId = ++requestIdRef.current
    setLoaded(false)
    setError(null)
    try {
      const params = branchId ? `?branch=${branchId}` : ''
      const data = await authFetch<StaffDashboardDto>(`/v1/dashboard/staff${params}`, { method: 'GET' })
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

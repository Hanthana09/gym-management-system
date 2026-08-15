import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { DashboardSummaryDto } from './types'

/**
 * functional requirements §10.1: today's check-ins/revenue/active-member
 * count. The live check-in counter itself stays on useLiveAttendanceCount
 * (Phase 5's Mercure mechanism, unchanged — gym-wide only). `branchId`
 * omitted/null means the gym-wide rollup (functional requirements §14.5).
 */
export function useDashboardSummary(branchId?: string | null) {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<DashboardSummaryDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const query = branchId ? `?branch_id=${branchId}` : ''
    const data = await authFetch<DashboardSummaryDto>(`/reports/dashboard${query}`, { method: 'GET' })
    setSummary(data)
    setLoaded(true)
  }, [authFetch, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { summary, loaded, refresh }
}

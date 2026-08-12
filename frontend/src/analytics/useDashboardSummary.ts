import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { DashboardSummaryDto } from './types'

/** functional requirements §10.1: today's check-ins/revenue/active-member count. The live check-in counter itself stays on useLiveAttendanceCount (Phase 5's Mercure mechanism, unchanged). */
export function useDashboardSummary() {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<DashboardSummaryDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<DashboardSummaryDto>('/reports/dashboard', { method: 'GET' })
    setSummary(data)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { summary, loaded, refresh }
}

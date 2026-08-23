import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { OwnerDashboardDto } from './types'

export function useOwnerDashboard(branchId: string | null) {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<OwnerDashboardDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    setLoaded(false)
    const params = branchId ? `?branch=${branchId}` : ''
    const data = await authFetch<OwnerDashboardDto>(`/v1/dashboard/owner${params}`, { method: 'GET' })
    setSummary(data)
    setLoaded(true)
  }, [authFetch, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { summary, loaded, refresh }
}

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MemberDashboardDto } from './types'

/** No branch param at all — Member stays hub-wide across branches, unlike Staff/Coach. */
export function useMemberDashboard() {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<MemberDashboardDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<MemberDashboardDto>('/v1/dashboard/member', { method: 'GET' })
    setSummary(data)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { summary, loaded, refresh }
}

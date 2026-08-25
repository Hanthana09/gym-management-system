import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { BillingStatusDto } from './types'

/**
 * gym-management-billing-v1.md §6: GET /members/{id}/billing-status —
 * used by the Member profile's billing badge/Payments tab (Owner/Staff/
 * Coach) and the Member's own dashboard card (self, memberId = own
 * user.id, same convention as GET /members/{id}/attendance/active
 * elsewhere in this codebase).
 */
export function useMemberBillingStatus(memberId: string | null) {
  const { authFetch } = useAuth()
  const [status, setStatus] = useState<BillingStatusDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    if (!memberId) {
      setStatus(null)
      setLoaded(true)
      return
    }
    const data = await authFetch<BillingStatusDto>(`/members/${memberId}/billing-status`, { method: 'GET' })
    setStatus(data)
    setLoaded(true)
  }, [authFetch, memberId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { status, loaded, refresh }
}

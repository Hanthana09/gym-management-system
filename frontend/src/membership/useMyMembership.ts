import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MembershipDto } from './types'

export function useMyMembership() {
  const { authFetch } = useAuth()
  // null = not loaded yet; explicit `{ membership: null }` response = loaded, none exists.
  const [membership, setMembership] = useState<MembershipDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ membership: MembershipDto | null }>('/members/me/membership', { method: 'GET' })
    setMembership(data.membership)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const pause = useCallback(async () => {
    const updated = await authFetch<MembershipDto>('/members/me/membership/pause', { method: 'PATCH' })
    setMembership(updated)
  }, [authFetch])

  const resume = useCallback(async () => {
    const updated = await authFetch<MembershipDto>('/members/me/membership/resume', { method: 'PATCH' })
    setMembership(updated)
  }, [authFetch])

  const cancel = useCallback(async () => {
    const updated = await authFetch<MembershipDto>('/members/me/membership/cancel', { method: 'PATCH' })
    setMembership(updated)
  }, [authFetch])

  return { membership, loaded, pause, resume, cancel }
}

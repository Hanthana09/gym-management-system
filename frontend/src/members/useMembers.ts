import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MemberListItemDto } from './types'

export function useMembers() {
  const { authFetch } = useAuth()
  const [members, setMembers] = useState<MemberListItemDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ members: MemberListItemDto[] }>('/members', { method: 'GET' })
    setMembers(data.members)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { members, loaded, refresh }
}

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { RetentionMemberDto } from './types'

/** functional requirements §10.4: each at-risk member with their specific reason(s). */
export function useRetention() {
  const { authFetch } = useAuth()
  const [members, setMembers] = useState<RetentionMemberDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ members: RetentionMemberDto[] }>('/reports/retention', { method: 'GET' })
    setMembers(data.members)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { members, loaded }
}

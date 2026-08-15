import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { RetentionMemberDto } from './types'

/** functional requirements §10.4: each at-risk member with their specific reason(s). `branchId` omitted/null means the gym-wide rollup (functional requirements §14.5). */
export function useRetention(branchId?: string | null) {
  const { authFetch } = useAuth()
  const [members, setMembers] = useState<RetentionMemberDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const query = branchId ? `?branch_id=${branchId}` : ''
    const data = await authFetch<{ members: RetentionMemberDto[] }>(`/reports/retention${query}`, { method: 'GET' })
    setMembers(data.members)
    setLoaded(true)
  }, [authFetch, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { members, loaded }
}

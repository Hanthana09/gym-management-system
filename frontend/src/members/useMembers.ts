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

  /** architecture doc §7: PATCH /members/:id/status — suspend/reactivate (Update/Delete: there's no hard-delete, see backend MemberService's docblock). */
  const updateStatus = useCallback(
    async (id: string, status: 'active' | 'suspended') => {
      const updated = await authFetch<MemberListItemDto>(`/members/${id}/status`, { method: 'PATCH', body: { status } })
      setMembers((prev) => prev.map((member) => (member.id === id ? { ...member, status: updated.status } : member)))

      return updated
    },
    [authFetch],
  )

  return { members, loaded, refresh, updateStatus }
}

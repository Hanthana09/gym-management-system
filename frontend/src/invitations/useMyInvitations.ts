import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { InvitationDto } from './types'

/**
 * Invitee-side view: "shown immediately after login if one exists ...
 * must not be missable" (functional requirements §2.2).
 */
export function useMyInvitations() {
  const { authFetch } = useAuth()
  // null = not loaded yet, distinct from an empty array (none exist).
  const [invitations, setInvitations] = useState<InvitationDto[] | null>(null)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ invitations: InvitationDto[] }>('/invitations/me', { method: 'GET' })
    setInvitations(data.invitations)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const approve = useCallback(
    async (id: string) => {
      const updated = await authFetch<InvitationDto>(`/invitations/${id}/approve`, { method: 'PATCH' })
      setInvitations((prev) => prev?.map((i) => (i.id === id ? updated : i)) ?? null)
    },
    [authFetch],
  )

  const decline = useCallback(
    async (id: string) => {
      const updated = await authFetch<InvitationDto>(`/invitations/${id}/decline`, { method: 'PATCH' })
      setInvitations((prev) => prev?.map((i) => (i.id === id ? updated : i)) ?? null)
    },
    [authFetch],
  )

  return { invitations, approve, decline }
}

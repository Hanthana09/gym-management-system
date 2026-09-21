import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { InvitationDto, InvitationRole } from './types'

/**
 * Owner-side invitation list. Backed by `GET /invitations` (functional
 * requirements §2.1: "the invitation shows as 'pending' in my invitations
 * list") — fetched on mount so a page reload, or a batch sent elsewhere
 * (e.g. the bulk-import screen at /owner/import, which doesn't go through
 * `sendInvitation` below), still shows up here. Mercure then keeps
 * individual rows live for the rest of the session as invitees respond.
 */
export function useOwnerInvitations() {
  const { authFetch } = useAuth()
  const [invitations, setInvitations] = useState<InvitationDto[]>([])
  const [gymId, setGymId] = useState<string | null>(null)

  const upsert = useCallback((invitation: InvitationDto) => {
    setInvitations((prev) => {
      const exists = prev.some((i) => i.id === invitation.id)

      return exists
        ? prev.map((i) => (i.id === invitation.id ? invitation : i))
        : [invitation, ...prev]
    })
    setGymId(invitation.gymId)
  }, [])

  useEffect(() => {
    authFetch<{ invitations: InvitationDto[] }>('/invitations', { method: 'GET' })
      .then((data) => {
        setInvitations(data.invitations)
        if (data.invitations.length > 0) setGymId(data.invitations[0].gymId)
      })
      .catch(() => {
        // Best-effort: an Owner who hasn't sent any invitations yet, or a
        // transient failure, just leaves the list empty — sendInvitation
        // below still works standalone.
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const sendInvitation = useCallback(
    async (destination: string, role: InvitationRole) => {
      const invitation = await authFetch<InvitationDto>('/invitations', { body: { destination, role } })
      upsert(invitation)

      return invitation
    },
    [authFetch, upsert],
  )

  /** Owner closing their own still-pending invitation — e.g. a bulk-import row that was a mistake or duplicate. */
  const cancelInvitation = useCallback(
    async (id: string) => {
      const invitation = await authFetch<InvitationDto>(`/invitations/${id}/cancel`, { method: 'PATCH' })
      upsert(invitation)

      return invitation
    },
    [authFetch, upsert],
  )

  // Live updates when the invitee responds (roadmap Phase 3 Definition of Done).
  useEffect(() => {
    if (!gymId) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `gym/${gymId}/invitations`)
    const source = new EventSource(url)

    source.onmessage = (event) => {
      const update = JSON.parse(event.data) as Pick<InvitationDto, 'id' | 'status'>
      setInvitations((prev) =>
        prev.map((invitation) =>
          invitation.id === update.id ? { ...invitation, status: update.status } : invitation,
        ),
      )
    }

    return () => source.close()
  }, [gymId])

  return { invitations, sendInvitation, cancelInvitation }
}

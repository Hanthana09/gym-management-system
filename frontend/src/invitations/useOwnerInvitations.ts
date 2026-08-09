import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { InvitationDto, InvitationRole } from './types'

/**
 * Owner-side invitation list. There's no "list all invitations I've sent"
 * endpoint in architecture doc §7 — only POST /invitations, GET
 * /invitations/me (invitee-scoped), and the approve/decline actions — so
 * this list is seeded from each POST response and kept live via Mercure
 * for the rest of the session. A page reload starts it empty again; a
 * fuller Owner-facing listing endpoint is a natural follow-up, not built
 * here since it isn't in this phase's documented API surface.
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

  const sendInvitation = useCallback(
    async (destination: string, role: InvitationRole) => {
      const invitation = await authFetch<InvitationDto>('/invitations', { body: { destination, role } })
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

  return { invitations, sendInvitation }
}

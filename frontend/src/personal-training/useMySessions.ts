import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { PtSessionDto } from './types'

/**
 * Member's "My sessions" list (functional requirements §5.1). Live
 * updates via Mercure use a full refetch rather than useOwnerInvitations'
 * patch-by-id style (Phase 3): a session's coach/plan details never
 * reached this browser before a status-changing event happens here, so
 * there's nothing local to patch — the same reasoning applies symmetrically
 * on the Coach side in useCoachSchedule.
 */
export function useMySessions() {
  const { authFetch, user } = useAuth()
  const [sessions, setSessions] = useState<PtSessionDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ sessions: PtSessionDto[] }>('/pt-sessions/me', { method: 'GET' })
    setSessions(data.sessions)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (!user) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `member/${user.id}/pt-sessions`)
    const source = new EventSource(url)
    source.onmessage = () => {
      void refresh()
    }

    return () => source.close()
  }, [user, refresh])

  const requestSession = useCallback(
    async (coachUserId: string, scheduledAt: string, durationMinutes: number) => {
      const session = await authFetch<PtSessionDto>('/pt-sessions', {
        body: { coachUserId, scheduledAt, durationMinutes },
      })
      setSessions((prev) => [session, ...prev])

      return session
    },
    [authFetch],
  )

  /** functional requirements §5.1: cancel a still-pending request. */
  const cancel = useCallback(
    async (id: string) => {
      const updated = await authFetch<PtSessionDto>(`/pt-sessions/${id}/status`, {
        method: 'PATCH',
        body: { status: 'cancelled' },
      })
      setSessions((prev) => prev.map((session) => (session.id === id ? updated : session)))
    },
    [authFetch],
  )

  return { sessions, loaded, requestSession, cancel }
}

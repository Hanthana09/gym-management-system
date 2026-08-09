import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { PtSessionDto } from './types'

/** Coach's schedule/agenda (functional requirements §5.2). */
export function useCoachSchedule() {
  const { authFetch, user } = useAuth()
  const [sessions, setSessions] = useState<PtSessionDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    if (!user) return
    const data = await authFetch<{ sessions: PtSessionDto[] }>(`/coaches/${user.id}/schedule`, { method: 'GET' })
    setSessions(data.sessions)
    setLoaded(true)
  }, [authFetch, user])

  useEffect(() => {
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (!user) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `coach/${user.id}/pt-sessions`)
    const source = new EventSource(url)
    source.onmessage = () => {
      void refresh()
    }

    return () => source.close()
  }, [user, refresh])

  const respond = useCallback(
    async (id: string, accept: boolean) => {
      const updated = await authFetch<PtSessionDto>(`/pt-sessions/${id}/status`, {
        method: 'PATCH',
        body: { status: accept ? 'confirmed' : 'declined' },
      })
      setSessions((prev) => prev.map((session) => (session.id === id ? updated : session)))
    },
    [authFetch],
  )

  return { sessions, loaded, respond }
}

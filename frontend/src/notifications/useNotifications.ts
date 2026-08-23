import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { NotificationDto } from './types'

/**
 * roadmap Phase 7: the bell's data source. Live updates via Mercure only
 * carry an unread count (see NotificationMercurePublisher on the
 * backend) — a full refetch on each message is what actually pulls in
 * the new row's title/body/type, the same reasoning already used for
 * useMySessions/useCoachSchedule in Phase 6.
 */
export function useNotifications() {
  const { authFetch, user } = useAuth()
  const [notifications, setNotifications] = useState<NotificationDto[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    // gym-management-dashboard-redesign.md Phase 0: the canonical list is
    // now API Platform's GetCollection at /v1/notifications — jsonld is
    // its default format (kept unchanged for Exercise's own consumption
    // elsewhere), so this explicitly asks for the flat-array format
    // instead of unwrapping a {member, totalItems} envelope.
    const data = await authFetch<NotificationDto[]>('/v1/notifications', {
      method: 'GET',
      headers: { Accept: 'application/json' },
    })
    setNotifications(data)
    setUnreadCount(data.filter((n) => !n.read).length)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (!user) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `user/${user.id}/notifications`)
    const source = new EventSource(url)
    source.onmessage = () => {
      void refresh()
    }

    return () => source.close()
  }, [user, refresh])

  const markRead = useCallback(
    async (id: string) => {
      const updated = await authFetch<NotificationDto>(`/v1/notifications/${id}`, { method: 'PATCH' })
      setNotifications((prev) => prev.map((n) => (n.id === id ? updated : n)))
      setUnreadCount((prev) => Math.max(0, prev - 1))
    },
    [authFetch],
  )

  const markAllRead = useCallback(async () => {
    await authFetch('/v1/notifications/mark-all-read', { method: 'POST' })
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })))
    setUnreadCount(0)
  }, [authFetch])

  return { notifications, unreadCount, loaded, markRead, markAllRead }
}

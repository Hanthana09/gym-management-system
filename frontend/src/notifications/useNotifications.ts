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
    const data = await authFetch<{ notifications: NotificationDto[]; unreadCount: number }>('/notifications', {
      method: 'GET',
    })
    setNotifications(data.notifications)
    setUnreadCount(data.unreadCount)
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
      const updated = await authFetch<NotificationDto>(`/notifications/${id}/read`, { method: 'PATCH' })
      setNotifications((prev) => prev.map((n) => (n.id === id ? updated : n)))
      setUnreadCount((prev) => Math.max(0, prev - 1))
    },
    [authFetch],
  )

  return { notifications, unreadCount, loaded, markRead }
}

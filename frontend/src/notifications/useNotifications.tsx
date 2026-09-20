import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { NotificationDto } from './types'

interface NotificationsContextValue {
  notifications: NotificationDto[]
  unreadCount: number
  loaded: boolean
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
}

const NotificationsContext = createContext<NotificationsContextValue | null>(null)

/**
 * A Context, not a plain per-component hook — NavShell's NotificationBell
 * (mounted on every authenticated screen) and the full-page
 * NotificationsPage both read this simultaneously. A plain
 * `useState`-per-call-site hook (this component's original shape) left
 * NotificationsPage's own `markRead`/`markAllRead` invisible in the
 * bell's unread badge until the next Mercure push remounted/refetched
 * its separate copy — same staleness this app already hit once with
 * gym branding (see useGymBranding.tsx's GymBrandingProvider docblock,
 * the only other cross-component shared state here besides AuthContext).
 * One shared instance means an action from either place is reflected in
 * both immediately.
 */
export function NotificationsProvider({ children }: { children: ReactNode }) {
  const { authFetch, user, status } = useAuth()
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
    // Same guard as useGymBranding.tsx's GymBrandingProvider, and for the
    // same reason: this provider now wraps the whole app (including
    // /login), not just NavShell-nested screens, so it must not fire an
    // authenticated request before there's a token to send. Re-runs on
    // every fresh 'authenticated' transition too, clearing out a previous
    // session's notifications before the next login's own arrive.
    if (status !== 'authenticated') return
    void refresh()
  }, [status, refresh])

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

  return (
    <NotificationsContext.Provider value={{ notifications, unreadCount, loaded, markRead, markAllRead }}>
      {children}
    </NotificationsContext.Provider>
  )
}

export function useNotifications(): NotificationsContextValue {
  const context = useContext(NotificationsContext)
  if (!context) throw new Error('useNotifications must be used within a NotificationsProvider')

  return context
}

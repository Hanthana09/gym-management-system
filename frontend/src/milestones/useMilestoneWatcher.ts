import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { MilestoneInfo } from './types'

const MILESTONE_TITLE = 'Milestone reached!'

/**
 * roadmap Phase 9.3: independently subscribes to the same Mercure topic
 * Phase 7's NotificationBell already listens to (`user/{id}/notifications`)
 * — a second, separate EventSource rather than modifying
 * useNotifications.ts/NotificationBell.tsx. Phase 7's frontend code stays
 * untouched, the same "zero changes" rule the backend followed.
 * Milestone notifications don't have a distinct NotificationType (the
 * backend reuses 'system' — see MilestoneNotificationSubscriber's own
 * comment on why), so this matches on the notification's title, a
 * pragmatic tradeoff for a single, first-party-controlled string.
 */
export function useMilestoneWatcher() {
  const { authFetch, user } = useAuth()
  const [milestone, setMilestone] = useState<MilestoneInfo | null>(null)
  const seenIds = useRef<Set<string>>(new Set())

  useEffect(() => {
    if (!user) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `user/${user.id}/notifications`)
    const source = new EventSource(url)

    source.onmessage = () => {
      void authFetch<{ notifications: { id: string; title: string; body: string }[] }>('/notifications', {
        method: 'GET',
      }).then((data) => {
        const latest = data.notifications[0]
        if (!latest || latest.title !== MILESTONE_TITLE || seenIds.current.has(latest.id)) return

        seenIds.current.add(latest.id)
        const match = latest.body.match(/(\d+)-day/)
        if (match) {
          setMilestone({ notificationId: latest.id, streakDays: Number(match[1]) })
        }
      })
    }

    return () => source.close()
  }, [user, authFetch])

  return { milestone, dismiss: () => setMilestone(null) }
}

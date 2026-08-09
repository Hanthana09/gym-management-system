import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { AnnouncementAudience } from './types'

interface AnnouncementResult {
  id: string
  body: string
  audience: AnnouncementAudience
  createdAt: string
  recipientCount: number
}

/**
 * functional requirements §6.2/§6.3. `audience` is decided by the caller
 * based on role (Owner always sends 'gym_wide', Coach always sends
 * 'own_clients') — the composer UI never offers a choice, matching
 * roadmap Phase 7's "the UI shouldn't even offer a gym-wide option to a
 * Coach" instruction. The backend enforces this independently either way
 * (AnnouncementVoter).
 */
export function useAnnouncements() {
  const { authFetch } = useAuth()

  const publish = useCallback(
    (body: string, audience: AnnouncementAudience) =>
      authFetch<AnnouncementResult>('/announcements', { body: { body, audience } }),
    [authFetch],
  )

  return { publish }
}

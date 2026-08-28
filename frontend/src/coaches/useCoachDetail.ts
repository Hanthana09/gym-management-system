import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { CoachProfileDetailDto } from './types'

/**
 * gym-management-coach-management.md: the Owner Coach Detail screen.
 * Loads GET /coaches/:id eagerly; `refresh` re-fetches after an edit,
 * status change, or password set.
 */
export function useCoachDetail(coachId: string) {
  const { authFetch } = useAuth()
  const [coach, setCoach] = useState<CoachProfileDetailDto | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [notFound, setNotFound] = useState(false)

  const refresh = useCallback(async () => {
    try {
      const data = await authFetch<CoachProfileDetailDto>(`/coaches/${coachId}`, { method: 'GET' })
      setCoach(data)
    } catch {
      setNotFound(true)
    } finally {
      setLoaded(true)
    }
  }, [authFetch, coachId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { coach, loaded, notFound, refresh }
}

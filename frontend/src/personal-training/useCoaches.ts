import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { CoachDto } from './types'

/** roadmap Phase 6: source for the Member's coach picker. */
export function useCoaches() {
  const { authFetch } = useAuth()
  const [coaches, setCoaches] = useState<CoachDto[]>([])
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    let cancelled = false

    authFetch<{ coaches: CoachDto[] }>('/coaches', { method: 'GET' }).then((data) => {
      if (cancelled) return
      setCoaches(data.coaches)
      setLoaded(true)
    })

    return () => {
      cancelled = true
    }
  }, [authFetch])

  return { coaches, loaded }
}

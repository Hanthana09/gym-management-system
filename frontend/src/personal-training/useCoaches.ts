import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { CoachDto } from './types'

/**
 * roadmap Phase 6, retrofitted Phase 16: source for the Member's coach
 * picker. functional requirements §14.3 — a Member can pick any branch
 * where at least one Coach is assigned, not just their enrolling branch —
 * so this is branch-scoped; `branchId` omitted means the gym's primary
 * branch, same default the backend applies (single-branch gyms unchanged).
 */
export function useCoaches(branchId?: string | null) {
  const { authFetch } = useAuth()
  const [coaches, setCoaches] = useState<CoachDto[]>([])
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoaded(false)
    const query = branchId ? `?branchId=${branchId}` : ''

    authFetch<{ coaches: CoachDto[] }>(`/coaches${query}`, { method: 'GET' }).then((data) => {
      if (cancelled) return
      setCoaches(data.coaches)
      setLoaded(true)
    })

    return () => {
      cancelled = true
    }
  }, [authFetch, branchId])

  return { coaches, loaded }
}

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { BranchDto } from './types'

/**
 * roadmap Phase 16.1: read-only branch list, available to every role
 * (architecture doc §7: "any authenticated user in the gym — read-only,
 * needed for branch pickers in forms"). This is the one hook every
 * branch-aware screen shares — the BranchSwitcher component and the
 * front-desk/PT-booking/announcement-composer retrofits all read from
 * this, not a screen-local fetch.
 */
export function useBranches() {
  const { authFetch } = useAuth()
  const [branches, setBranches] = useState<BranchDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ branches: BranchDto[] }>('/branches', { method: 'GET' })
    setBranches(data.branches)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { branches, loaded, refresh }
}

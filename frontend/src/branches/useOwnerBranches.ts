import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { AssignableUserDto, BranchDto, BranchStatus } from './types'

/** roadmap Phase 16.1: Owner's branch management screen — CRUD + Coach/Staff assignment, all gated by BranchVoter::MANAGE server-side. */
export function useOwnerBranches() {
  const { authFetch } = useAuth()
  const [branches, setBranches] = useState<BranchDto[]>([])
  const [assignableUsers, setAssignableUsers] = useState<AssignableUserDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const [branchData, userData] = await Promise.all([
      authFetch<{ branches: BranchDto[] }>('/branches', { method: 'GET' }),
      authFetch<{ users: AssignableUserDto[] }>('/branches/assignable-users', { method: 'GET' }),
    ])
    setBranches(branchData.branches)
    setAssignableUsers(userData.users)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createBranch = useCallback(
    async (name: string, address: string, phone?: string) => {
      const branch = await authFetch<BranchDto>('/branches', { method: 'POST', body: { name, address, phone } })
      await refresh()

      return branch
    },
    [authFetch, refresh],
  )

  const updateBranch = useCallback(
    async (id: string, changes: { name?: string; address?: string; phone?: string; status?: BranchStatus }) => {
      const branch = await authFetch<BranchDto>(`/branches/${id}`, { method: 'PATCH', body: changes })
      await refresh()

      return branch
    },
    [authFetch, refresh],
  )

  const assign = useCallback(
    async (branchId: string, userId: string) => {
      const branch = await authFetch<BranchDto>(`/branches/${branchId}/assign`, { method: 'POST', body: { userId } })
      await refresh()

      return branch
    },
    [authFetch, refresh],
  )

  const unassign = useCallback(
    async (branchId: string, userId: string) => {
      const branch = await authFetch<BranchDto>(`/branches/${branchId}/assign/${userId}`, { method: 'DELETE' })
      await refresh()

      return branch
    },
    [authFetch, refresh],
  )

  /**
   * Branch delete facility: a genuine hard delete, only ever possible for
   * a branch the backend confirms has never been used (no attendance,
   * plans, or PT sessions) and isn't the primary branch — otherwise a 409
   * (`branch_in_use` / `primary_branch`) surfaces as an ApiError for the
   * page to show. Deactivate (already on this page) is still the tool for
   * removing a branch that HAS been used.
   */
  const deleteBranch = useCallback(
    async (id: string) => {
      await authFetch<null>(`/branches/${id}`, { method: 'DELETE' })
      await refresh()
    },
    [authFetch, refresh],
  )

  return { branches, assignableUsers, loaded, refresh, createBranch, updateBranch, assign, unassign, deleteBranch }
}

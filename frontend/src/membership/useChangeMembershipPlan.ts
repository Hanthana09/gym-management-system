import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MembershipDto } from './types'

/**
 * Owner reassigning an existing member's plan — architecture doc §6.2/§9
 * "plan changes," the counterpart to useEnrollMember for a member who
 * already has an ongoing membership. PATCH /memberships/:id/plan.
 */
export function useChangeMembershipPlan() {
  const { authFetch } = useAuth()

  const changePlan = useCallback(
    async (membershipId: string, planId: string) => {
      return authFetch<MembershipDto>(`/memberships/${membershipId}/plan`, { method: 'PATCH', body: { planId } })
    },
    [authFetch],
  )

  return { changePlan }
}

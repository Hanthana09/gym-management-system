import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MembershipPlanDto } from './types'

export interface PlanInput {
  name: string
  price: string
  durationDays: number
  features: string[]
}

/**
 * roadmap Phase 16: plans are branch-scoped — "which branch's plans am I
 * editing," per the roadmap's own framing. `branchId` omitted (or null)
 * means the gym's primary branch, same default the backend applies — a
 * single-branch gym's screen behaves exactly as it did before this phase.
 */
export function useOwnerPlans(branchId?: string | null) {
  const { authFetch } = useAuth()
  const [plans, setPlans] = useState<MembershipPlanDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const query = branchId ? `?branchId=${branchId}` : ''
    const data = await authFetch<{ plans: MembershipPlanDto[]; branchId: string }>(`/membership-plans${query}`, { method: 'GET' })
    setPlans(data.plans)
    setLoaded(true)
  }, [authFetch, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createPlan = useCallback(
    async (input: PlanInput) => {
      const plan = await authFetch<MembershipPlanDto>('/membership-plans', { body: { ...input, branchId } })
      setPlans((prev) => [...prev, plan])

      return plan
    },
    [authFetch, branchId],
  )

  const updatePlan = useCallback(
    async (id: string, input: PlanInput) => {
      const plan = await authFetch<MembershipPlanDto>(`/membership-plans/${id}`, { method: 'PATCH', body: input })
      setPlans((prev) => prev.map((p) => (p.id === id ? plan : p)))

      return plan
    },
    [authFetch],
  )

  const deletePlan = useCallback(
    async (id: string) => {
      await authFetch<null>(`/membership-plans/${id}`, { method: 'DELETE' })
      setPlans((prev) => prev.filter((p) => p.id !== id))
    },
    [authFetch],
  )

  return { plans, loaded, refresh, createPlan, updatePlan, deletePlan }
}

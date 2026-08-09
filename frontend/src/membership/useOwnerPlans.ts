import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MembershipPlanDto } from './types'

export interface PlanInput {
  name: string
  price: string
  durationDays: number
  features: string[]
}

export function useOwnerPlans() {
  const { authFetch } = useAuth()
  const [plans, setPlans] = useState<MembershipPlanDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ plans: MembershipPlanDto[] }>('/membership-plans', { method: 'GET' })
    setPlans(data.plans)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createPlan = useCallback(
    async (input: PlanInput) => {
      const plan = await authFetch<MembershipPlanDto>('/membership-plans', { body: input })
      setPlans((prev) => [...prev, plan])

      return plan
    },
    [authFetch],
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

  return { plans, loaded, createPlan, updatePlan, deletePlan }
}

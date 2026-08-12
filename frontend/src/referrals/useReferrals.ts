import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ReferralLeadDto } from './types'

/** roadmap Phase 9.2 — shared by both the Coach and Owner screens (both can submit and see their own leads). */
export function useReferrals() {
  const { authFetch } = useAuth()
  const [leads, setLeads] = useState<ReferralLeadDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ leads: ReferralLeadDto[] }>('/referrals/me', { method: 'GET' })
    setLeads(data.leads)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const submitLead = useCallback(
    async (prospectGymName: string, contactName: string, contactEmail: string, contactPhone: string) => {
      const lead = await authFetch<ReferralLeadDto>('/referrals', {
        body: {
          prospectGymName,
          contactName: contactName || undefined,
          contactEmail: contactEmail || undefined,
          contactPhone: contactPhone || undefined,
        },
      })
      setLeads((prev) => [lead, ...prev])

      return lead
    },
    [authFetch],
  )

  return { leads, loaded, submitLead }
}

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { FinancialSummaryDto } from './types'

/**
 * functional requirements §15.4 / architecture doc §6.13, §7:
 * GET /financial-summary — hand-written FinancialSummaryController, plain
 * `/api` prefix (not `/api/v1`, same split as `/reports/*`), gated by the
 * existing ReportVoter::VIEW — Owner, own gym only. `branchId`
 * omitted/null means the gym-wide rollup (same default rule as every
 * other Owner report, functional requirements §14.5).
 */
export function useFinancialSummary(from: string, to: string, branchId?: string | null) {
  const { authFetch } = useAuth()
  const [summary, setSummary] = useState<FinancialSummaryDto | null>(null)
  const [loading, setLoading] = useState(false)

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const branchQuery = branchId ? `&branch_id=${branchId}` : ''
      const data = await authFetch<FinancialSummaryDto>(`/financial-summary?from=${from}&to=${to}${branchQuery}`, {
        method: 'GET',
      })
      setSummary(data)
    } finally {
      setLoading(false)
    }
  }, [authFetch, from, to, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { summary, loading, refresh }
}

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { AttentionInvoiceDto } from './types'

/**
 * gym-management-billing-v1.md §6/§7: GET /branches/{id}/invoices?status=
 * absent,overdue — the Owner/Staff dashboard "needs attention" widget's
 * data source, already sorted oldest-due-first by the backend.
 */
export function useBranchBillingAttention(branchId: string | null) {
  const { authFetch } = useAuth()
  const [invoices, setInvoices] = useState<AttentionInvoiceDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    if (!branchId) {
      setInvoices([])
      setLoaded(true)
      return
    }
    const data = await authFetch<{ invoices: AttentionInvoiceDto[] }>(
      `/branches/${branchId}/invoices?status=absent,overdue`,
      { method: 'GET' },
    )
    setInvoices(data.invoices)
    setLoaded(true)
  }, [authFetch, branchId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { invoices, loaded, refresh }
}

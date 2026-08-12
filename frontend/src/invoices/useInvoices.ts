import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { InvoiceDto, OwnerSelectablePaymentMethod } from './types'

/** Owner: GET /invoices (architecture doc §7) — all invoices for their gym. */
export function useInvoices() {
  const { authFetch } = useAuth()
  const [invoices, setInvoices] = useState<InvoiceDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ invoices: InvoiceDto[] }>('/invoices', { method: 'GET' })
    setInvoices(data.invoices)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const markPaid = useCallback(
    async (id: string, paymentMethod: OwnerSelectablePaymentMethod) => {
      const updated = await authFetch<InvoiceDto>(`/invoices/${id}/mark-paid`, {
        method: 'PATCH',
        body: { paymentMethod },
      })
      setInvoices((prev) => prev.map((invoice) => (invoice.id === id ? updated : invoice)))

      return updated
    },
    [authFetch],
  )

  return { invoices, loaded, markPaid }
}

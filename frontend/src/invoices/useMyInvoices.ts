import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { InvoiceDto } from './types'

/** Member: GET /members/me/invoices (architecture doc §7) — own invoices only. */
export function useMyInvoices() {
  const { authFetch } = useAuth()
  const [invoices, setInvoices] = useState<InvoiceDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ invoices: InvoiceDto[] }>('/members/me/invoices', { method: 'GET' })
    setInvoices(data.invoices)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { invoices, loaded }
}

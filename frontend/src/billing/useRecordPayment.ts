import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { PaymentResultDto, RecurringPaymentMethod } from './types'

/** gym-management-billing-v1.md §5.2: POST /invoices/{id}/payments. */
export function useRecordPayment() {
  const { authFetch } = useAuth()

  const recordPayment = useCallback(
    async (invoiceId: string, amount: string, method: RecurringPaymentMethod, resetBillingCycle: boolean) => {
      return authFetch<PaymentResultDto>(`/invoices/${invoiceId}/payments`, {
        method: 'POST',
        body: { amount, method, resetBillingCycle },
      })
    },
    [authFetch],
  )

  return { recordPayment }
}

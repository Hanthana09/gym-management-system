export type InvoiceStatus = 'pending' | 'paid' | 'failed'

// 'gateway'/'referral_credit' are real values the API can return (a
// referral-credit-covered invoice, or a future gateway payment) but are
// never offered as a choice in the Owner's mark-paid selector — see
// backend PaymentMethod's docblock.
export type PaymentMethod = 'cash' | 'bank_transfer' | 'gateway' | 'referral_credit'

// The only two values the Owner's "Mark as paid" action may submit.
export type OwnerSelectablePaymentMethod = 'cash' | 'bank_transfer'

export interface InvoiceDto {
  id: string
  amount: string
  status: InvoiceStatus
  paymentMethod: PaymentMethod | null
  recordedByName: string | null
  issuedAt: string
  paidAt: string | null
  plan: {
    name: string
  }
  // Present only on the Owner's /invoices (GET /invoices), absent on the
  // Member's own /members/me/invoices — same shape difference as the
  // backend's serialize(..., includeMember:) split.
  member?: {
    id: string
    name: string
  }
}

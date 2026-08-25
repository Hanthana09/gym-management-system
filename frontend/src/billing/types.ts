// gym-management-billing-v1.md — recurring billing (merged into the
// existing Membership/Invoice model, see backend's Membership/Invoice
// entity docblocks). Deliberately distinct from ../invoices/types.ts,
// which covers the older one-time enrollment-invoice flow
// (InvoiceStatus 'pending'|'paid'|'failed', no periodStart/dueDate) —
// these are the new recurring-cycle shapes.

/** cash/card/bank_transfer — the Owner/Staff-selectable methods for the recurring payment endpoint (§3.3). */
export type RecurringPaymentMethod = 'cash' | 'card' | 'bank_transfer'

export type OutstandingInvoiceStatus = 'pending' | 'absent'

export interface OutstandingInvoiceDto {
  id: string
  periodStart: string | null
  dueDate: string | null
  amount: string
  status: OutstandingInvoiceStatus
}

/** GET /members/{id}/billing-status response shape (§6). */
export interface BillingStatusDto {
  subscriptionStatus: string | null
  eligibleForCheckIn: boolean
  blockReason: string | null
  outstandingInvoices: OutstandingInvoiceDto[]
}

/** One row of GET /branches/{id}/invoices?status=absent,overdue — the dashboard "needs attention" widget's data source. */
export interface AttentionInvoiceDto {
  id: string
  status: OutstandingInvoiceStatus
  amount: string
  periodStart: string | null
  dueDate: string | null
  member: {
    id: string
    name: string
  }
}

/** POST /invoices/{id}/payments response shape. */
export interface PaymentResultDto {
  id: string
  invoiceId: string
  amount: string
  method: RecurringPaymentMethod
  resetBillingCycle: boolean
  note: string | null
  paidAt: string
  invoice: {
    id: string
    status: string
    amount: string
  }
}

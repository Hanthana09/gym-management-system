import { Link } from 'react-router-dom'
import { Card, Ticket } from '../components/ui'
import { useMyInvoices } from '../invoices/useMyInvoices'
import type { InvoiceDto, InvoiceStatus } from '../invoices/types'

const STATUS_STYLES: Record<InvoiceStatus, string> = {
  pending: 'bg-amber-100 text-amber-800',
  paid: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
}

function StatusTag({ status }: { status: InvoiceStatus }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${STATUS_STYLES[status]}`}>
      {status}
    </span>
  )
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}

/**
 * functional requirements §8.2: date, amount, status, and — for paid
 * invoices — payment method + when it was recorded. Not a bottom-nav tab
 * (roadmap Phase 1 fixes the Member tab bar to Check-in/Sessions/
 * Tracking/Notifications) — reached from a HomePage card, same pattern
 * as MyMembershipCard.
 */
export function MemberInvoicesPage() {
  const { invoices, loaded } = useMyInvoices()

  return (
    <div className="min-h-dvh bg-paper px-4 py-6">
      <div className="mx-auto max-w-2xl">
        <Link to="/" className="text-sm text-ink-soft hover:underline">
          ← Back
        </Link>
        <h1 className="mt-1 mb-4 font-display text-lg font-semibold tracking-wide text-ink uppercase">Billing</h1>

        {loaded && invoices.length === 0 ? (
          <Card>
            <p className="py-6 text-center text-sm text-ink-soft">No invoices yet.</p>
          </Card>
        ) : null}

        <ul className="flex flex-col gap-3">
          {invoices.map((invoice) => (
            <li key={invoice.id}>
              <InvoiceRow invoice={invoice} />
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function InvoiceRow({ invoice }: { invoice: InvoiceDto }) {
  return (
    <Ticket className="flex items-center justify-between gap-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{invoice.plan.name}</p>
        <p className="font-mono text-xs text-ink-soft">${invoice.amount}</p>
        <p className="mt-0.5 text-xs text-ink-soft">
          {invoice.status === 'paid' && invoice.paidAt
            ? `Paid ${formatDate(invoice.paidAt)} · ${invoice.paymentMethod === 'bank_transfer' ? 'bank transfer' : invoice.paymentMethod}`
            : `Issued ${formatDate(invoice.issuedAt)}`}
        </p>
      </div>
      <StatusTag status={invoice.status} />
    </Ticket>
  )
}

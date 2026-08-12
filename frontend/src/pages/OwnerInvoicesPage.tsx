import { useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Modal, Select, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useInvoices } from '../invoices/useInvoices'
import type { InvoiceDto, InvoiceStatus, OwnerSelectablePaymentMethod } from '../invoices/types'

// DESIGN-SYSTEM.md §3 "Tag/pill" pattern, same approach as every other
// status tag in this codebase (MyMembershipCard, MemberSessionsPage).
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

type FilterValue = 'outstanding' | 'paid' | 'all'

const FILTER_OPTIONS: { value: FilterValue; label: string }[] = [
  { value: 'outstanding', label: 'Outstanding' },
  { value: 'paid', label: 'Paid' },
  { value: 'all', label: 'All' },
]

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}

/**
 * roadmap Phase 10: the outstanding-invoices view is "the actual
 * day-to-day screen for this phase, more so than history" — defaults to
 * showing pending invoices, with a filter to switch to paid/all. Ticket
 * pattern for rows (DESIGN-SYSTEM.md §3/§4 — each invoice is exactly the
 * kind of discrete, scannable event that pattern fits).
 */
export function OwnerInvoicesPage() {
  const { invoices, loaded, markPaid } = useInvoices()
  const [filter, setFilter] = useState<FilterValue>('outstanding')
  const [markingInvoice, setMarkingInvoice] = useState<InvoiceDto | null>(null)

  const visibleInvoices = useMemo(() => {
    if (filter === 'all') return invoices
    if (filter === 'outstanding') return invoices.filter((i) => i.status === 'pending')

    return invoices.filter((i) => i.status === 'paid')
  }, [invoices, filter])

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/invoices">
        <div className="mx-auto flex max-w-2xl flex-col gap-4">
          <div className="flex items-center justify-between gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Billing</h1>
            <div className="w-40">
              <Select
                label="Show"
                value={filter}
                onChange={(e) => setFilter(e.target.value as FilterValue)}
                options={FILTER_OPTIONS}
              />
            </div>
          </div>

          {loaded && invoices.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No invoices yet.</p>
            </Card>
          ) : null}

          {loaded && invoices.length > 0 && visibleInvoices.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">
                {filter === 'outstanding' ? 'Nothing outstanding — all invoices are paid.' : 'No invoices match this filter.'}
              </p>
            </Card>
          ) : null}

          <ul className="flex flex-col gap-3">
            {visibleInvoices.map((invoice) => (
              <li key={invoice.id}>
                <Ticket className="flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{invoice.member?.name ?? 'Member'}</p>
                    <p className="font-mono text-xs text-ink-soft">
                      {invoice.plan.name} · ${invoice.amount}
                    </p>
                    <p className="mt-0.5 text-xs text-ink-soft">
                      {invoice.status === 'paid' && invoice.paidAt
                        ? `Paid ${formatDate(invoice.paidAt)} · ${invoice.paymentMethod} · recorded by ${invoice.recordedByName}`
                        : `Issued ${formatDate(invoice.issuedAt)}`}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <StatusTag status={invoice.status} />
                    {invoice.status === 'pending' ? (
                      <Button variant="secondary" onClick={() => setMarkingInvoice(invoice)}>
                        Mark as paid
                      </Button>
                    ) : null}
                  </div>
                </Ticket>
              </li>
            ))}
          </ul>
        </div>

        <MarkPaidModal invoice={markingInvoice} onClose={() => setMarkingInvoice(null)} onMarkPaid={markPaid} />
      </NavShell>
    </div>
  )
}

interface MarkPaidModalProps {
  invoice: InvoiceDto | null
  onClose: () => void
  onMarkPaid: (id: string, method: OwnerSelectablePaymentMethod) => Promise<InvoiceDto>
}

/**
 * "A financial action worth one deliberate tap, not accidental" (roadmap
 * Phase 10) — payment method selector, then an explicit confirm step
 * inside the same modal rather than submitting on selection.
 */
function MarkPaidModal({ invoice, onClose, onMarkPaid }: MarkPaidModalProps) {
  const [method, setMethod] = useState<OwnerSelectablePaymentMethod>('cash')
  const [confirming, setConfirming] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function handleClose() {
    setConfirming(false)
    setMethod('cash')
    setError(null)
    onClose()
  }

  async function handleConfirm() {
    if (!invoice) return
    setSubmitting(true)
    setError(null)
    try {
      await onMarkPaid(invoice.id, method)
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Modal open={invoice !== null} onClose={handleClose} title="Mark as paid">
      {invoice ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink-soft">
            {invoice.member?.name} · {invoice.plan.name} · <span className="font-mono">${invoice.amount}</span>
          </p>

          {!confirming ? (
            <>
              <Select
                label="Payment method"
                value={method}
                onChange={(e) => setMethod(e.target.value as OwnerSelectablePaymentMethod)}
                options={[
                  { value: 'cash', label: 'Cash' },
                  { value: 'bank_transfer', label: 'Bank transfer' },
                ]}
              />
              <Button fullWidth onClick={() => setConfirming(true)}>
                Continue
              </Button>
            </>
          ) : (
            <>
              <p className="text-sm text-ink">
                Confirm you received <span className="font-mono">${invoice.amount}</span> via{' '}
                <strong>{method === 'cash' ? 'cash' : 'bank transfer'}</strong>?
              </p>
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              <div className="flex gap-2">
                <Button variant="secondary" fullWidth onClick={() => setConfirming(false)} disabled={submitting}>
                  Back
                </Button>
                <Button fullWidth onClick={handleConfirm} disabled={submitting}>
                  {submitting ? 'Recording…' : 'Confirm payment'}
                </Button>
              </div>
            </>
          )}
        </div>
      ) : null}
    </Modal>
  )
}

import { useState } from 'react'
import { Button, Modal, Select } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { useRecordPayment } from './useRecordPayment'
import type { PaymentResultDto, RecurringPaymentMethod } from './types'

export interface InvoiceToPay {
  id: string
  amount: string
  memberName?: string
}

interface RecordPaymentModalProps {
  invoice: InvoiceToPay | null
  onClose: () => void
  onRecorded: (result: PaymentResultDto) => void
}

const METHOD_LABELS: Record<RecurringPaymentMethod, string> = {
  cash: 'cash',
  card: 'card',
  bank_transfer: 'bank transfer',
}

/**
 * gym-management-billing-v1.md §7: amount pre-filled and locked to
 * invoice.amount — no free-entry field, reinforcing no-partial-payment at
 * the UI level too. resetBillingCycle checkbox renders only for Owner
 * sessions, unchecked by default (the backend Voter is the real gate —
 * this is the stated UX nicety on top). Shared by the dashboard "needs
 * attention" widget and the Member profile's Payments tab. Adapted from
 * OwnerInvoicesPage's MarkPaidModal (same two-step confirm shape) for
 * this new recurring-payment endpoint.
 */
export function RecordPaymentModal({ invoice, onClose, onRecorded }: RecordPaymentModalProps) {
  const { user } = useAuth()
  const { recordPayment } = useRecordPayment()
  const isOwner = user?.role === 'owner'
  const [method, setMethod] = useState<RecurringPaymentMethod>('cash')
  const [resetBillingCycle, setResetBillingCycle] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function handleClose() {
    setConfirming(false)
    setMethod('cash')
    setResetBillingCycle(false)
    setError(null)
    onClose()
  }

  async function handleConfirm() {
    if (!invoice) return
    setSubmitting(true)
    setError(null)
    try {
      const result = await recordPayment(invoice.id, invoice.amount, method, isOwner && resetBillingCycle)
      onRecorded(result)
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Modal open={invoice !== null} onClose={handleClose} title="Record payment">
      {invoice ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink-soft">
            {invoice.memberName ? `${invoice.memberName} · ` : null}
            <span className="font-mono">${invoice.amount}</span>
          </p>

          {!confirming ? (
            <>
              <Select
                label="Payment method"
                value={method}
                onChange={(e) => setMethod(e.target.value as RecurringPaymentMethod)}
                options={[
                  { value: 'cash', label: 'Cash' },
                  { value: 'card', label: 'Card' },
                  { value: 'bank_transfer', label: 'Bank transfer' },
                ]}
              />
              {isOwner ? (
                <label className="flex items-center gap-2 text-sm text-ink">
                  <input
                    type="checkbox"
                    checked={resetBillingCycle}
                    onChange={(e) => setResetBillingCycle(e.target.checked)}
                    className="h-4 w-4 rounded border-line accent-ink"
                  />
                  Reset billing cycle to this payment's date
                </label>
              ) : null}
              <Button fullWidth onClick={() => setConfirming(true)}>
                Continue
              </Button>
            </>
          ) : (
            <>
              <p className="text-sm text-ink">
                Confirm you received <span className="font-mono">${invoice.amount}</span> via{' '}
                <strong>{METHOD_LABELS[method]}</strong>?
                {isOwner && resetBillingCycle ? ' The billing cycle will reset to today.' : ''}
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

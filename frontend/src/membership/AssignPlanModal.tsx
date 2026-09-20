import { useState } from 'react'
import { Button, Modal, Select } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import type { MembershipPlanDto } from './types'

interface AssignPlanModalProps {
  member: { id: string; name: string } | null
  // Present when this member already has an ongoing membership — switches
  // the modal from "enroll" (no plan yet) to "change plan" (has one),
  // and preselects the current plan.
  currentPlanId?: string | null
  plans: MembershipPlanDto[]
  plansLoaded: boolean
  onClose: () => void
  onSubmit: (planId: string) => Promise<unknown>
  onAssigned: () => Promise<unknown>
}

/**
 * "Enroll in plan" for a member with no membership yet, or "Change plan"
 * for one who already has an ongoing membership — same picker either
 * way, just a different verb, current selection, and submit action.
 * Enrolling auto-creates a pending Invoice (roadmap Phase 10); changing
 * plans takes effect from the next invoice, current period unaffected
 * (MembershipService::changePlan's "no retroactive changes" rule).
 */
export function AssignPlanModal({ member, currentPlanId, plans, plansLoaded, onClose, onSubmit, onAssigned }: AssignPlanModalProps) {
  const [planId, setPlanId] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const isChange = Boolean(currentPlanId)
  const selectedPlanId = planId || currentPlanId || plans[0]?.id || ''

  function handleClose() {
    setPlanId('')
    setError(null)
    setSubmitting(false)
    onClose()
  }

  async function handleSubmit() {
    if (!member || !selectedPlanId) return
    if (isChange && selectedPlanId === currentPlanId) {
      handleClose()
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onSubmit(selectedPlanId)
      await onAssigned()
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Modal open={member !== null} onClose={handleClose} title={isChange ? 'Change plan' : 'Enroll in plan'}>
      {member ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink-soft">{member.name}</p>

          {!plansLoaded ? (
            <p className="text-sm text-ink-soft">Loading plans…</p>
          ) : plans.length === 0 ? (
            <p className="text-sm text-ink-soft">No plans yet — create one first.</p>
          ) : (
            <>
              <Select
                label="Plan"
                value={selectedPlanId}
                onChange={(e) => setPlanId(e.target.value)}
                options={plans.map((plan) => ({ value: plan.id, label: `${plan.name} — $${plan.price} / ${plan.durationDays} days` }))}
              />
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              <Button fullWidth onClick={handleSubmit} disabled={submitting}>
                {submitting ? (isChange ? 'Saving…' : 'Enrolling…') : isChange ? 'Save plan' : 'Enroll'}
              </Button>
            </>
          )}
        </div>
      ) : null}
    </Modal>
  )
}

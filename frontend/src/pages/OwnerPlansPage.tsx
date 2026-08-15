import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useOwnerPlans, type PlanInput } from '../membership/useOwnerPlans'
import type { MembershipPlanDto } from '../membership/types'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'

/**
 * "Card grid on mobile, table at lg:" (roadmap Phase 4) — built from
 * Card/Button/Input/Modal, no new primitives.
 */
export function OwnerPlansPage() {
  const { branches } = useBranches()
  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)
  const effectiveBranchId = selectedBranchId ?? defaultBranchId(branches)
  const { plans, loaded, createPlan, updatePlan, deletePlan } = useOwnerPlans(effectiveBranchId)
  const [editingPlan, setEditingPlan] = useState<MembershipPlanDto | 'new' | null>(null)
  const [confirmingDeleteId, setConfirmingDeleteId] = useState<string | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  async function handleDelete(id: string) {
    setDeleteError(null)
    try {
      await deletePlan(id)
      setConfirmingDeleteId(null)
    } catch (err) {
      setDeleteError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/plans">
        <div className="mx-auto max-w-4xl">
        <div className="mb-4 flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <h1 className="truncate font-display text-lg font-semibold tracking-wide text-ink uppercase">
              Membership Plans
            </h1>
            {/* DESIGN-SYSTEM.md §4.2: absent entirely for single-branch gyms — no chrome to show here. */}
            <BranchSwitcher branches={branches} value={effectiveBranchId} onChange={setSelectedBranchId} />
          </div>
          <Button onClick={() => setEditingPlan('new')}>New plan</Button>
        </div>

        {loaded && plans.length === 0 ? (
          <Card>
            <p className="py-6 text-center text-sm text-ink-soft">No plans yet.</p>
          </Card>
        ) : null}

        {/* Card grid — default (mobile/tablet) */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:hidden">
          {plans.map((plan) => (
            <Card key={plan.id}>
              <h2 className="text-base font-semibold text-ink">{plan.name}</h2>
              <p className="mt-1 text-sm text-ink-soft">
                ${plan.price} / {plan.durationDays} days
              </p>
              {plan.features.length > 0 ? (
                <ul className="mt-2 list-inside list-disc text-sm text-ink-soft">
                  {plan.features.map((feature) => (
                    <li key={feature}>{feature}</li>
                  ))}
                </ul>
              ) : null}
              <PlanRowActions
                plan={plan}
                confirmingDelete={confirmingDeleteId === plan.id}
                onEdit={() => setEditingPlan(plan)}
                onRequestDelete={() => {
                  setDeleteError(null)
                  setConfirmingDeleteId(plan.id)
                }}
                onCancelDelete={() => setConfirmingDeleteId(null)}
                onConfirmDelete={() => handleDelete(plan.id)}
                deleteError={confirmingDeleteId === plan.id ? deleteError : null}
              />
            </Card>
          ))}
        </div>

        {/* Table — lg: and up */}
        {plans.length > 0 ? (
          <table className="hidden w-full border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
            <thead>
              <tr className="text-left text-sm text-ink-soft">
                <th className="border-b border-line px-4 py-3">Name</th>
                <th className="border-b border-line px-4 py-3">Price</th>
                <th className="border-b border-line px-4 py-3">Duration</th>
                <th className="border-b border-line px-4 py-3">Features</th>
                <th className="border-b border-line px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              {plans.map((plan) => (
                <tr key={plan.id} className="text-sm text-ink">
                  <td className="border-b border-line/60 px-4 py-3 font-medium">{plan.name}</td>
                  <td className="border-b border-line/60 px-4 py-3">${plan.price}</td>
                  <td className="border-b border-line/60 px-4 py-3">{plan.durationDays} days</td>
                  <td className="border-b border-line/60 px-4 py-3 text-ink-soft">{plan.features.join(', ')}</td>
                  <td className="border-b border-line/60 px-4 py-3">
                    <PlanRowActions
                      plan={plan}
                      confirmingDelete={confirmingDeleteId === plan.id}
                      onEdit={() => setEditingPlan(plan)}
                      onRequestDelete={() => {
                        setDeleteError(null)
                        setConfirmingDeleteId(plan.id)
                      }}
                      onCancelDelete={() => setConfirmingDeleteId(null)}
                      onConfirmDelete={() => handleDelete(plan.id)}
                      deleteError={confirmingDeleteId === plan.id ? deleteError : null}
                      compact
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}

        <PlanFormModal
          open={editingPlan !== null}
          plan={editingPlan === 'new' ? null : editingPlan}
          onClose={() => setEditingPlan(null)}
          onCreate={createPlan}
          onUpdate={updatePlan}
        />
        </div>
      </NavShell>
    </div>
  )
}

interface PlanRowActionsProps {
  plan: MembershipPlanDto
  confirmingDelete: boolean
  onEdit: () => void
  onRequestDelete: () => void
  onCancelDelete: () => void
  onConfirmDelete: () => void
  deleteError: string | null
  compact?: boolean
}

function PlanRowActions({
  confirmingDelete,
  onEdit,
  onRequestDelete,
  onCancelDelete,
  onConfirmDelete,
  deleteError,
  compact,
}: PlanRowActionsProps) {
  if (confirmingDelete) {
    return (
      <div className={compact ? 'flex flex-col gap-2' : 'mt-4 flex flex-col gap-2'}>
        <p className="text-sm text-ink">Delete this plan?</p>
        {deleteError ? <p className="text-sm text-red-600">{deleteError}</p> : null}
        <div className="flex gap-2">
          <Button variant="secondary" fullWidth={!compact} onClick={onCancelDelete}>
            Cancel
          </Button>
          <Button variant="danger" fullWidth={!compact} onClick={onConfirmDelete}>
            Delete
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className={compact ? 'flex gap-2' : 'mt-4 flex gap-2'}>
      <Button variant="secondary" fullWidth={!compact} onClick={onEdit}>
        Edit
      </Button>
      <Button variant="danger" fullWidth={!compact} onClick={onRequestDelete}>
        Delete
      </Button>
    </div>
  )
}

interface PlanFormModalProps {
  open: boolean
  plan: MembershipPlanDto | null
  onClose: () => void
  onCreate: (input: PlanInput) => Promise<MembershipPlanDto>
  onUpdate: (id: string, input: PlanInput) => Promise<MembershipPlanDto>
}

function PlanFormModal({ open, plan, onClose, onCreate, onUpdate }: PlanFormModalProps) {
  const [name, setName] = useState(plan?.name ?? '')
  const [price, setPrice] = useState(plan?.price ?? '')
  const [durationDays, setDurationDays] = useState(String(plan?.durationDays ?? '30'))
  const [features, setFeatures] = useState(plan?.features.join(', ') ?? '')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Re-seed the form every time the modal opens (not just when the plan
  // identity changes) — otherwise reopening "New plan" after typing
  // something and closing without saving would show the stale draft.
  const [wasOpen, setWasOpen] = useState(false)
  if (open && !wasOpen) {
    setWasOpen(true)
    setName(plan?.name ?? '')
    setPrice(plan?.price ?? '')
    setDurationDays(String(plan?.durationDays ?? '30'))
    setFeatures(plan?.features.join(', ') ?? '')
    setError(null)
  } else if (!open && wasOpen) {
    setWasOpen(false)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    const input: PlanInput = {
      name,
      price,
      durationDays: Number(durationDays),
      features: features
        .split(',')
        .map((f) => f.trim())
        .filter(Boolean),
    }

    try {
      if (plan) {
        await onUpdate(plan.id, input)
      } else {
        await onCreate(input)
      }
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={plan ? 'Edit plan' : 'New plan'}>
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input
          label="Price"
          type="number"
          step="0.01"
          min="0"
          value={price}
          onChange={(e) => setPrice(e.target.value)}
          required
        />
        <Input
          label="Duration (days)"
          type="number"
          min="1"
          value={durationDays}
          onChange={(e) => setDurationDays(e.target.value)}
          required
        />
        <Input
          label="Features"
          hint="Comma-separated, e.g. Gym floor access, Sauna"
          value={features}
          onChange={(e) => setFeatures(e.target.value)}
        />
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Saving…' : 'Save'}
        </Button>
      </form>
    </Modal>
  )
}

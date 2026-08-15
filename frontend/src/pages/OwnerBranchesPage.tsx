import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Select } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useOwnerBranches } from '../branches/useOwnerBranches'
import type { BranchDto } from '../branches/types'

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

/**
 * roadmap Phase 16.1: Owner's branch management — list/create/edit/
 * deactivate, plus Coach/Staff assignment. Card list only (no lg: table
 * split like OwnerPlansPage/OwnerMembersPage) — a business's branch count
 * is small enough that a dense table adds no value here.
 */
export function OwnerBranchesPage() {
  const { branches, assignableUsers, loaded, createBranch, updateBranch, assign, unassign } = useOwnerBranches()
  const [editingBranch, setEditingBranch] = useState<BranchDto | 'new' | null>(null)
  const [assigningBranch, setAssigningBranch] = useState<BranchDto | null>(null)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [busyBranchId, setBusyBranchId] = useState<string | null>(null)

  async function handleToggleStatus(branch: BranchDto) {
    setStatusError(null)
    setBusyBranchId(branch.id)
    try {
      await updateBranch(branch.id, { status: branch.status === 'active' ? 'inactive' : 'active' })
    } catch (err) {
      setStatusError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setBusyBranchId(null)
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/branches">
        <div className="mx-auto max-w-2xl">
          <div className="mb-4 flex items-center justify-between">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Branches</h1>
            <Button onClick={() => setEditingBranch('new')}>New branch</Button>
          </div>

          {statusError ? <p className="mb-3 text-sm text-red-600">{statusError}</p> : null}

          {loaded && branches.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No branches yet.</p>
            </Card>
          ) : null}

          <div className="flex flex-col gap-4">
            {branches.map((branch) => (
              <Card key={branch.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <h2 className="truncate text-base font-semibold text-ink">{branch.name}</h2>
                      {branch.isPrimary ? <Pill label="Primary" styles="bg-owner-soft text-owner" /> : null}
                    </div>
                    <p className="mt-0.5 text-sm text-ink-soft">{branch.address}</p>
                    {branch.phone ? <p className="text-sm text-ink-soft">{branch.phone}</p> : null}
                  </div>
                  <Pill
                    label={branch.status}
                    styles={branch.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}
                  />
                </div>

                <div className="mt-3 border-t border-line pt-3">
                  <p className="mb-2 text-xs font-medium tracking-wide text-ink-soft uppercase">
                    Assigned Coach / Staff
                  </p>
                  {branch.assignments.length === 0 ? (
                    <p className="text-sm text-ink-soft">No one assigned yet.</p>
                  ) : (
                    <ul className="flex flex-col gap-1.5">
                      {branch.assignments.map((a) => (
                        <li key={a.userId} className="flex items-center justify-between gap-2">
                          <span className="text-sm text-ink">
                            {a.name} <span className="text-ink-soft capitalize">· {a.role}</span>
                          </span>
                          <button
                            type="button"
                            onClick={() => unassign(branch.id, a.userId)}
                            className="text-xs font-medium text-ink-soft underline hover:text-ink"
                          >
                            Remove
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>

                <div className="mt-3 flex gap-2">
                  <Button variant="secondary" fullWidth onClick={() => setEditingBranch(branch)}>
                    Edit
                  </Button>
                  <Button variant="secondary" fullWidth onClick={() => setAssigningBranch(branch)}>
                    Assign
                  </Button>
                  <Button
                    variant={branch.status === 'active' ? 'danger' : 'secondary'}
                    fullWidth
                    disabled={busyBranchId === branch.id}
                    onClick={() => handleToggleStatus(branch)}
                  >
                    {branch.status === 'active' ? 'Deactivate' : 'Activate'}
                  </Button>
                </div>
              </Card>
            ))}
          </div>

          <BranchFormModal
            open={editingBranch !== null}
            branch={editingBranch === 'new' ? null : editingBranch}
            onClose={() => setEditingBranch(null)}
            onCreate={createBranch}
            onUpdate={updateBranch}
          />

          <AssignModal
            branch={assigningBranch}
            assignableUsers={assignableUsers}
            onClose={() => setAssigningBranch(null)}
            onAssign={assign}
          />
        </div>
      </NavShell>
    </div>
  )
}

interface BranchFormModalProps {
  open: boolean
  branch: BranchDto | null
  onClose: () => void
  onCreate: (name: string, address: string, phone?: string) => Promise<BranchDto>
  onUpdate: (id: string, changes: { name?: string; address?: string; phone?: string }) => Promise<BranchDto>
}

function BranchFormModal({ open, branch, onClose, onCreate, onUpdate }: BranchFormModalProps) {
  const [name, setName] = useState(branch?.name ?? '')
  const [address, setAddress] = useState(branch?.address ?? '')
  const [phone, setPhone] = useState(branch?.phone ?? '')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [wasOpen, setWasOpen] = useState(false)
  if (open && !wasOpen) {
    setWasOpen(true)
    setName(branch?.name ?? '')
    setAddress(branch?.address ?? '')
    setPhone(branch?.phone ?? '')
    setError(null)
  } else if (!open && wasOpen) {
    setWasOpen(false)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      if (branch) {
        await onUpdate(branch.id, { name, address, phone: phone || undefined })
      } else {
        await onCreate(name, address, phone || undefined)
      }
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={branch ? 'Edit branch' : 'New branch'}>
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label="Address" value={address} onChange={(e) => setAddress(e.target.value)} required />
        <Input label="Phone" hint="Optional" value={phone} onChange={(e) => setPhone(e.target.value)} />
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Saving…' : 'Save'}
        </Button>
      </form>
    </Modal>
  )
}

interface AssignModalProps {
  branch: BranchDto | null
  assignableUsers: { id: string; name: string; role: 'coach' | 'staff' }[]
  onClose: () => void
  onAssign: (branchId: string, userId: string) => Promise<BranchDto>
}

function AssignModal({ branch, assignableUsers, onClose, onAssign }: AssignModalProps) {
  const unassigned = branch
    ? assignableUsers.filter((u) => !branch.assignments.some((a) => a.userId === u.id))
    : []
  const [userId, setUserId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const selectedUserId = userId || unassigned[0]?.id || ''

  function handleClose() {
    setUserId('')
    setError(null)
    onClose()
  }

  async function handleSubmit() {
    if (!branch || !selectedUserId) return
    setSubmitting(true)
    setError(null)
    try {
      await onAssign(branch.id, selectedUserId)
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Modal open={branch !== null} onClose={handleClose} title="Assign to branch">
      {branch ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink-soft">{branch.name}</p>

          {unassigned.length === 0 ? (
            <p className="text-sm text-ink-soft">Every Coach/Staff account is already assigned here.</p>
          ) : (
            <>
              <Select
                label="Coach or Staff"
                value={selectedUserId}
                onChange={(e) => setUserId(e.target.value)}
                options={unassigned.map((u) => ({ value: u.id, label: `${u.name} (${u.role})` }))}
              />
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              <Button fullWidth onClick={handleSubmit} disabled={submitting}>
                {submitting ? 'Assigning…' : 'Assign'}
              </Button>
            </>
          )}
        </div>
      ) : null}
    </Modal>
  )
}

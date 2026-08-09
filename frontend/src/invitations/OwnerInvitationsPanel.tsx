import { useState, type FormEvent } from 'react'
import { Button, Card, Input, Modal, Select, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useOwnerInvitations } from './useOwnerInvitations'
import type { InvitationDto, InvitationRole, InvitationStatus } from './types'

// DESIGN-SYSTEM.md §3 "Tag/pill" typographic pattern, applied inline —
// these statuses keep their own semantic colors, not the role palette.
const STATUS_STYLES: Record<InvitationStatus, string> = {
  pending: 'bg-amber-100 text-amber-800',
  approved: 'bg-green-100 text-green-800',
  declined: 'bg-red-100 text-red-800',
  expired: 'bg-gray-100 text-gray-600',
}

function StatusBadge({ status }: { status: InvitationStatus }) {
  return (
    <span
      className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${STATUS_STYLES[status]}`}
    >
      {status}
    </span>
  )
}

/**
 * Reachable from a FAB on mobile, a toolbar button at md: (roadmap Phase
 * 3). Built from Card/Button/Input/Select/Modal — no new primitives.
 */
export function OwnerInvitationsPanel() {
  const { invitations, sendInvitation } = useOwnerInvitations()
  const [modalOpen, setModalOpen] = useState(false)

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="font-display text-base font-semibold tracking-wide text-ink uppercase">
          Invitations
        </h2>
        {/* Toggling visibility on a wrapper, not the Button itself — Button
            already sets its own unconditional `inline-flex`, which would
            fight a same-tier `hidden` passed via className. */}
        <div className="hidden md:block">
          <Button onClick={() => setModalOpen(true)}>Invite</Button>
        </div>
      </div>

      {invitations.length === 0 ? (
        <p className="py-6 text-center text-sm text-ink-soft">No invitations sent yet.</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {invitations.map((invitation) => (
            <li key={invitation.id}>
              <Ticket className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-ink">{invitation.destination}</p>
                  <p className="text-xs text-ink-soft capitalize">{invitation.role}</p>
                </div>
                <StatusBadge status={invitation.status} />
              </Ticket>
            </li>
          ))}
        </ul>
      )}

      {/* Floating action button — mobile only (roadmap Phase 3) */}
      <div className="md:hidden">
        <Button
          className="fixed right-4 bottom-20 z-30 h-14 w-14 rounded-full p-0 shadow-lg"
          onClick={() => setModalOpen(true)}
          aria-label="Invite someone"
        >
          +
        </Button>
      </div>

      <InviteModal open={modalOpen} onClose={() => setModalOpen(false)} onSent={sendInvitation} />
    </Card>
  )
}

interface InviteModalProps {
  open: boolean
  onClose: () => void
  onSent: (destination: string, role: InvitationRole) => Promise<InvitationDto>
}

function InviteModal({ open, onClose, onSent }: InviteModalProps) {
  const [destination, setDestination] = useState('')
  const [role, setRole] = useState<InvitationRole>('member')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [sentInvitation, setSentInvitation] = useState<InvitationDto | null>(null)

  function reset() {
    setDestination('')
    setRole('member')
    setError(null)
    setSentInvitation(null)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const invitation = await onSent(destination, role)
      setSentInvitation(invitation)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => {
        onClose()
        reset()
      }}
      title="Invite someone"
    >
      {sentInvitation ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink-soft">
            Invitation sent to <span className="font-medium">{sentInvitation.destination}</span> as{' '}
            <span className="capitalize">{sentInvitation.role}</span>. It shows as pending until they
            respond.
          </p>
          <div className="flex gap-2">
            <Button variant="secondary" fullWidth onClick={reset}>
              Invite another
            </Button>
            <Button
              fullWidth
              onClick={() => {
                onClose()
                reset()
              }}
            >
              Done
            </Button>
          </div>
        </div>
      ) : (
        <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
          <Input
            label="Email or phone"
            placeholder="coach@example.com"
            value={destination}
            onChange={(event) => setDestination(event.target.value)}
            required
          />
          <Select
            label="Role"
            value={role}
            onChange={(event) => setRole(event.target.value as InvitationRole)}
            options={[
              { value: 'member', label: 'Member' },
              { value: 'coach', label: 'Coach' },
            ]}
          />
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Button type="submit" fullWidth disabled={submitting}>
            {submitting ? 'Sending…' : 'Send invitation'}
          </Button>
        </form>
      )}
    </Modal>
  )
}

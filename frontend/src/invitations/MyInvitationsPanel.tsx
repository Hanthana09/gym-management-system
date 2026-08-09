import { useState } from 'react'
import { Button, Card } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useMyInvitations } from './useMyInvitations'
import type { InvitationStatus } from './types'

/**
 * "Shown immediately after login if one exists ... must not be missable"
 * (functional requirements §2.2) — rendered above everything else on the
 * invitee's landing page, not tucked into a menu. Nothing renders when
 * there's no pending invitation; there's nothing to miss.
 */
export function MyInvitationsPanel() {
  const { invitations, approve, decline } = useMyInvitations()
  // Approving/declining flips status away from 'pending' immediately, which
  // would otherwise unmount the card before its confirmation ever renders —
  // keep anything resolved *this session* visible too.
  const [resolvedIds, setResolvedIds] = useState<ReadonlySet<string>>(new Set())

  const visible =
    invitations?.filter((invitation) => invitation.status === 'pending' || resolvedIds.has(invitation.id)) ?? []
  if (visible.length === 0) return null

  return (
    <div className="flex flex-col gap-3">
      {visible.map((invitation) => (
        <InvitationCard
          key={invitation.id}
          id={invitation.id}
          role={invitation.role}
          status={invitation.status}
          approve={approve}
          decline={decline}
          onResolved={() => setResolvedIds((prev) => new Set(prev).add(invitation.id))}
        />
      ))}
    </div>
  )
}

interface InvitationCardProps {
  id: string
  role: 'coach' | 'member'
  status: InvitationStatus
  approve: (id: string) => Promise<void>
  decline: (id: string) => Promise<void>
  onResolved: () => void
}

function InvitationCard({ id, role, status, approve, decline, onResolved }: InvitationCardProps) {
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<'approve' | 'decline' | null>(null)

  async function handle(action: 'approve' | 'decline') {
    setError(null)
    setBusy(action)

    try {
      await (action === 'approve' ? approve(id) : decline(id))
      onResolved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setBusy(null)
    }
  }

  if (status !== 'pending') {
    return (
      <Card className="border-2 border-ink">
        <p className="text-sm font-medium text-ink">
          {status === 'approved' ? `You've joined the gym as a ${role}.` : 'Invitation declined.'}
        </p>
      </Card>
    )
  }

  return (
    <Card className="border-2 border-ink">
      <p className="font-display text-sm font-semibold tracking-wide text-ink uppercase">
        You've been invited!
      </p>
      <p className="mt-1 text-sm text-ink-soft">
        You've been invited to join as a <span className="font-medium capitalize">{role}</span>.
      </p>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
      <div className="mt-4 flex gap-2">
        <Button variant="danger" fullWidth disabled={busy !== null} onClick={() => handle('decline')}>
          {busy === 'decline' ? 'Declining…' : 'Decline'}
        </Button>
        <Button fullWidth disabled={busy !== null} onClick={() => handle('approve')}>
          {busy === 'approve' ? 'Approving…' : 'Approve'}
        </Button>
      </div>
    </Card>
  )
}

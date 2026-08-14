import { useState } from 'react'
import { Badge, Button } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { useGymBranding } from '../gym/useGymBranding'
import { useMyMembership } from './useMyMembership'
import type { MembershipStatus } from './types'

// DESIGN-SYSTEM.md §3 "Tag/pill" typographic pattern, applied inline —
// these statuses keep their own semantic colors, not the role palette.
const STATUS_STYLES: Record<MembershipStatus, string> = {
  active: 'bg-green-100 text-green-800',
  paused: 'bg-amber-100 text-amber-800',
  expired: 'bg-gray-100 text-gray-600',
  cancelled: 'bg-red-100 text-red-800',
}

function StatusBadge({ status }: { status: MembershipStatus }) {
  return (
    <span
      className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${STATUS_STYLES[status]}`}
    >
      {status}
    </span>
  )
}

type PendingAction = 'pause' | 'resume' | 'cancel' | null

/**
 * DESIGN-SYSTEM.md §4 "one badge, every station" — the ID-card primitive
 * is reserved for exactly this screen (roadmap Phase 4).
 */
export function MyMembershipCard() {
  const { user } = useAuth()
  const { branding } = useGymBranding()
  const { membership, loaded, pause, resume, cancel } = useMyMembership()
  const [confirming, setConfirming] = useState<PendingAction>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!loaded || !membership || !user) return null

  const dateLabel = membership.status === 'active' ? 'Renews' : membership.status === 'expired' ? 'Expired' : 'Ends'
  const badgeNumber = `#${membership.id.replace(/-/g, '').slice(0, 8).toUpperCase()}`

  async function runAction(action: 'pause' | 'resume' | 'cancel') {
    setBusy(true)
    setError(null)

    try {
      await (action === 'pause' ? pause() : action === 'resume' ? resume() : cancel())
      setConfirming(null)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Badge role="member" name={user.name} badgeNumber={badgeNumber} brandColor={branding.brandColor}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-ink">{membership.plan.name}</h2>
          <p className="text-sm text-ink-soft">${membership.plan.price} / {membership.plan.durationDays} days</p>
        </div>
        <StatusBadge status={membership.status} />
      </div>

      <p className="mt-3 text-sm text-ink-soft">
        {dateLabel} <span className="font-medium">{membership.endDate}</span>
      </p>

      {membership.plan.features.length > 0 ? (
        <ul className="mt-3 list-inside list-disc text-sm text-ink-soft">
          {membership.plan.features.map((feature) => (
            <li key={feature}>{feature}</li>
          ))}
        </ul>
      ) : null}

      {error ? <p className="mt-3 text-sm text-red-600">{error}</p> : null}

      {confirming ? (
        <div className="mt-4 rounded-md bg-paper-dim p-3">
          <p className="text-sm text-ink">
            {confirming === 'pause' && 'Pause your membership? No further billing until you resume.'}
            {confirming === 'resume' && 'Resume your membership now?'}
            {confirming === 'cancel' && 'Cancel your membership? This cannot be undone.'}
          </p>
          <div className="mt-3 flex gap-2">
            <Button variant="secondary" fullWidth disabled={busy} onClick={() => setConfirming(null)}>
              Back
            </Button>
            <Button
              variant={confirming === 'cancel' ? 'danger' : 'primary'}
              fullWidth
              disabled={busy}
              onClick={() => runAction(confirming)}
            >
              {busy ? 'Working…' : 'Confirm'}
            </Button>
          </div>
        </div>
      ) : (
        <div className="mt-4 flex gap-2">
          {membership.status === 'active' ? (
            <>
              <Button variant="secondary" fullWidth onClick={() => setConfirming('pause')}>
                Pause
              </Button>
              <Button variant="danger" fullWidth onClick={() => setConfirming('cancel')}>
                Cancel
              </Button>
            </>
          ) : null}
          {membership.status === 'paused' ? (
            <>
              <Button fullWidth onClick={() => setConfirming('resume')}>
                Resume
              </Button>
              <Button variant="danger" fullWidth onClick={() => setConfirming('cancel')}>
                Cancel
              </Button>
            </>
          ) : null}
        </div>
      )}
    </Badge>
  )
}

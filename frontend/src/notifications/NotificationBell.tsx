import { useState, type FormEvent } from 'react'
import { Button, Modal } from '../components/ui'
import { BellIcon } from '../components/ui/icons'
import { cn } from '../lib/cn'
import { ApiError } from '../lib/apiClient'
import { useNotifications } from './useNotifications'
import { useAnnouncements } from './useAnnouncements'
import type { AnnouncementAudience, NotificationDto, SourceRole } from './types'

// DESIGN-SYSTEM.md §1: role colors are for identity, reused here as the
// "source role" tag color per this phase's spec — an Owner announcement
// gets the owner tag, a Coach-driven booking notification gets coach.
// Staff (Phase 15) has no identity color in DESIGN-SYSTEM.md §1's token
// set — a neutral gray, not a fourth invented brand color, same
// treatment already used for "no strong status" elsewhere (e.g. expired).
const SOURCE_ROLE_TAG: Record<SourceRole, string> = {
  owner: 'bg-owner-soft text-owner',
  coach: 'bg-coach-soft text-coach',
  staff: 'bg-gray-100 text-gray-600',
  member: 'bg-member-soft text-member',
}

/**
 * roadmap Phase 7: bell + unread badge, reusing the Phase 1 Modal
 * directly (bottom sheet on mobile, dialog at md:+ — no new visual
 * primitive needed). Rendered in NavShell's header, so every role gets
 * one. DESIGN-SYSTEM.md §1 explicitly names "unread badges" as a hivis
 * use case (distinct from Phase 6's "reserved for check-in" guidance,
 * which was about CTA buttons specifically) — the small count dot is the
 * only hivis in this component.
 */
export function NotificationBell({ role }: { role: 'owner' | 'coach' | 'member' | 'staff' }) {
  const { notifications, unreadCount, loaded, markRead } = useNotifications()
  const { publish } = useAnnouncements()
  const [open, setOpen] = useState(false)

  return (
    <>
      <button
        type="button"
        aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
        onClick={() => setOpen(true)}
        className="relative ml-auto flex min-h-touch min-w-touch items-center justify-center rounded-md text-ink hover:bg-paper-dim focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
      >
        <BellIcon />
        {unreadCount > 0 ? (
          <span className="absolute top-1.5 right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-hivis px-1 font-mono text-[10px] font-semibold text-ink">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        ) : null}
      </button>

      <Modal open={open} onClose={() => setOpen(false)} title="Notifications">
        {role === 'owner' || role === 'coach' ? <AnnouncementComposer role={role} onSent={publish} /> : null}

        {!loaded ? (
          <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
        ) : notifications.length === 0 ? (
          <p className="py-6 text-center text-sm text-ink-soft">No notifications yet.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {notifications.map((notification) => (
              <li key={notification.id}>
                <NotificationRow notification={notification} onMarkRead={markRead} />
              </li>
            ))}
          </ul>
        )}
      </Modal>
    </>
  )
}

function NotificationRow({
  notification,
  onMarkRead,
}: {
  notification: NotificationDto
  onMarkRead: (id: string) => Promise<void>
}) {
  return (
    <div className={cn('flex flex-col gap-1 rounded-md border border-line p-3', !notification.read && 'bg-paper-dim/60')}>
      <div className="flex items-center justify-between gap-2">
        <span
          className={cn(
            'rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase',
            notification.sourceRole ? SOURCE_ROLE_TAG[notification.sourceRole] : 'bg-paper-dim text-ink-soft',
          )}
        >
          {notification.type}
        </span>
        {!notification.read ? (
          <button
            type="button"
            onClick={() => onMarkRead(notification.id)}
            className="text-xs font-medium text-ink-soft underline hover:text-ink"
          >
            Mark read
          </button>
        ) : null}
      </div>
      <p className="text-sm font-medium text-ink">{notification.title}</p>
      <p className="text-sm text-ink-soft">{notification.body}</p>
      <p className="font-mono text-xs text-ink-soft">
        {new Date(notification.createdAt).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
      </p>
    </div>
  )
}

interface AnnouncementComposerProps {
  role: 'owner' | 'coach'
  onSent: (body: string, audience: AnnouncementAudience) => Promise<{ recipientCount: number }>
}

/**
 * functional requirements §6.2/§6.3: audience is fixed by role, never a
 * user choice — a Coach is never even shown a gym-wide option, per
 * roadmap Phase 7's explicit instruction not to rely on the backend
 * rejection alone.
 */
function AnnouncementComposer({ role, onSent }: AnnouncementComposerProps) {
  const [body, setBody] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSuccess(null)
    if (!body.trim()) return

    setSubmitting(true)
    try {
      const audience: AnnouncementAudience = role === 'owner' ? 'gym_wide' : 'own_clients'
      const result = await onSent(body.trim(), audience)
      setSuccess(`Sent to ${result.recipientCount} ${result.recipientCount === 1 ? 'person' : 'people'}.`)
      setBody('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="mb-4 flex flex-col gap-2 border-b border-line pb-4">
      <label className="text-sm font-medium text-ink">
        {role === 'owner' ? 'Send an announcement (gym-wide)' : 'Message your clients'}
      </label>
      <textarea
        value={body}
        onChange={(event) => setBody(event.target.value)}
        rows={3}
        placeholder={role === 'owner' ? 'Gym-wide update…' : 'Message for your clients…'}
        className="w-full rounded-md border border-line bg-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1"
      />
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      {success ? <p className="text-sm text-green-700">{success}</p> : null}
      <Button type="submit" disabled={submitting || !body.trim()}>
        {submitting ? 'Sending…' : 'Send'}
      </Button>
    </form>
  )
}

import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'

/**
 * roadmap Phase 15.3: "a simple toggle per channel, reachable from
 * account settings" — WhatsApp is the only channel with a preference at
 * all (email/in-app are always-on, per SendNotificationEmailMessageHandler's
 * own docblock), so this is a single toggle, not a settings sub-page.
 * Lives in the "Signed in" card on HomePage — the one place already
 * reachable by every role regardless of what else their home screen shows.
 */
export function NotificationPreferences() {
  const { user, authFetch } = useAuth()
  const [optIn, setOptIn] = useState(user?.whatsappOptIn ?? false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!user) return null

  async function handleToggle(next: boolean) {
    setOptIn(next)
    setError(null)
    setSaving(true)

    try {
      await authFetch<{ whatsappOptIn: boolean }>('/users/me/notification-preferences', {
        method: 'PATCH',
        body: { whatsappOptIn: next },
      })
    } catch (err) {
      setOptIn(!next)
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="border-t border-line pt-4 text-left">
      <label className="flex items-center justify-between gap-3">
        <span>
          <span className="block text-sm font-medium text-ink">WhatsApp notifications</span>
          <span className="block text-xs text-ink-soft">
            {user.phone ? 'Get booking and billing updates by WhatsApp.' : 'Add a phone number to your account to enable this.'}
          </span>
        </span>
        <input
          type="checkbox"
          role="switch"
          checked={optIn}
          disabled={saving || !user.phone}
          onChange={(e) => handleToggle(e.target.checked)}
          className="h-6 w-11 shrink-0 accent-ink"
          aria-label="WhatsApp notifications"
        />
      </label>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
    </div>
  )
}

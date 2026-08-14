import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input } from '../components/ui'
import { useGymBranding } from '../gym/useGymBranding'
import { useWhatsAppSettings } from '../gym/useWhatsAppSettings'
import { ApiError } from '../lib/apiClient'

const DEFAULT_BRAND_COLOR = '#1F2937'

/**
 * roadmap Phase 15.2 / DESIGN-SYSTEM.md §4.1: logo + one accent color,
 * nothing else — deliberately not a general "appearance" screen. The
 * fixed `/owner/settings` link in OWNER_NAV_ITEMS had no route behind it
 * until now (same dormant-link class of bug fixed in earlier phases).
 */
export function OwnerSettingsPage() {
  const { branding, loaded, updateBranding } = useGymBranding()
  const [logoFile, setLogoFile] = useState<File | null>(null)
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  const [brandColor, setBrandColor] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)

  const effectiveColor = brandColor ?? branding.brandColor ?? DEFAULT_BRAND_COLOR

  function handleLogoChange(file: File | null) {
    setLogoFile(file)
    setSuccess(false)
    setLogoPreview(file ? URL.createObjectURL(file) : null)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSuccess(false)
    setSubmitting(true)

    try {
      await updateBranding({
        logo: logoFile ?? undefined,
        brandColor: brandColor ?? undefined,
      })
      setLogoFile(null)
      setLogoPreview(null)
      setSuccess(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/settings">
        <div className="mx-auto flex max-w-lg flex-col gap-4">
          <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Branding</h1>
          <p className="text-sm text-ink-soft">
            Your logo and accent color appear on the navigation header and on members' digital membership
            badges. The check-in button and role colors always stay the product's standard colors.
          </p>

          <Card>
            <form onSubmit={handleSubmit} className="flex flex-col gap-4">
              <div>
                <label htmlFor="logo-upload" className="mb-1.5 block text-sm font-medium text-ink">
                  Logo
                </label>
                <div className="flex items-center gap-3">
                  {logoPreview || branding.logoUrl ? (
                    <img
                      src={logoPreview ?? branding.logoUrl ?? undefined}
                      alt="Gym logo"
                      className="h-12 w-12 rounded-md border border-line object-contain"
                    />
                  ) : (
                    <div className="flex h-12 w-12 items-center justify-center rounded-md border border-dashed border-line text-xs text-ink-soft">
                      None
                    </div>
                  )}
                  <input
                    id="logo-upload"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => handleLogoChange(e.target.files?.[0] ?? null)}
                    className="min-h-touch flex-1 text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-paper-dim file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink"
                  />
                </div>
              </div>

              <div>
                <label htmlFor="brand-color" className="mb-1.5 block text-sm font-medium text-ink">
                  Brand color
                </label>
                <div className="flex items-center gap-3">
                  <input
                    id="brand-color"
                    type="color"
                    value={effectiveColor}
                    onChange={(e) => {
                      setBrandColor(e.target.value)
                      setSuccess(false)
                    }}
                    className="h-11 w-16 rounded-md border border-line bg-card p-1"
                  />
                  <span className="font-mono text-sm text-ink-soft uppercase">{effectiveColor}</span>
                </div>
              </div>

              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              {success ? <p className="text-sm text-green-700">Branding saved.</p> : null}

              <Button type="submit" disabled={submitting || !loaded}>
                {submitting ? 'Saving…' : 'Save branding'}
              </Button>
            </form>
          </Card>

          <h1 className="mt-2 font-display text-lg font-semibold tracking-wide text-ink uppercase">
            WhatsApp notifications
          </h1>
          <p className="text-sm text-ink-soft">
            Set up your gym's WhatsApp Business number so members can opt in to booking and billing updates.
            This is a gym-wide switch — each member still opts in individually from their own account.
          </p>
          <WhatsAppSettingsSection />
        </div>
      </NavShell>
    </div>
  )
}

/**
 * Admin config follow-up to roadmap Phase 15.3: Owner-only, reuses
 * GymVoter::MANAGE like branding. Credentials are write-only from the
 * browser's perspective — the access token field never gets pre-filled
 * with a real value, only a "configured" placeholder, since
 * GET /gym/whatsapp-settings never echoes the token back once set.
 */
function WhatsAppSettingsSection() {
  const { settings, loaded, updateSettings } = useWhatsAppSettings()
  const [accessToken, setAccessToken] = useState('')
  const [phoneNumberId, setPhoneNumberId] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)

  const effectivePhoneNumberId = phoneNumberId ?? settings.phoneNumberId ?? ''

  async function handleSave(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSuccess(false)
    setSubmitting(true)

    try {
      await updateSettings({
        phoneNumberId: phoneNumberId ?? undefined,
        // Only sent when the Owner actually typed a new one — an empty
        // field must never accidentally clear an already-configured
        // token just because the input never round-trips its value.
        accessToken: accessToken !== '' ? accessToken : undefined,
      })
      setAccessToken('')
      setSuccess(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleToggleEnabled(next: boolean) {
    setError(null)
    setSuccess(false)

    try {
      await updateSettings({ enabled: next })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    }
  }

  return (
    <Card>
      <form onSubmit={handleSave} className="flex flex-col gap-4">
        <label className="flex items-center justify-between gap-3">
          <span>
            <span className="block text-sm font-medium text-ink">Enabled for this gym</span>
            <span className="block text-xs text-ink-soft">
              {settings.accessTokenSet ? 'Turns WhatsApp delivery on or off for every member.' : 'Add credentials below before enabling.'}
            </span>
          </span>
          <input
            type="checkbox"
            role="switch"
            checked={settings.enabled}
            disabled={!loaded || !settings.accessTokenSet}
            onChange={(e) => handleToggleEnabled(e.target.checked)}
            className="h-6 w-11 shrink-0 accent-ink"
            aria-label="Enabled for this gym"
          />
        </label>

        <Input
          label="Phone number ID"
          placeholder="e.g. 123456789012345"
          value={effectivePhoneNumberId}
          onChange={(e) => {
            setPhoneNumberId(e.target.value)
            setSuccess(false)
          }}
        />

        <Input
          label="Access token"
          type="password"
          placeholder={settings.accessTokenSet ? 'Configured — enter a new token to replace it' : 'Paste your WhatsApp Business Cloud API token'}
          value={accessToken}
          onChange={(e) => {
            setAccessToken(e.target.value)
            setSuccess(false)
          }}
          hint={settings.accessTokenSet ? 'A token is on file. It is never shown again once saved.' : undefined}
        />

        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        {success ? <p className="text-sm text-green-700">WhatsApp settings saved.</p> : null}

        <Button type="submit" variant="secondary" disabled={submitting || !loaded}>
          {submitting ? 'Saving…' : 'Save credentials'}
        </Button>
      </form>
    </Card>
  )
}

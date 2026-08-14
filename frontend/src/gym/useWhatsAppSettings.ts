import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'

export interface WhatsAppSettingsDto {
  enabled: boolean
  phoneNumberId: string | null
  /** The access token itself is never sent to the browser once set — only whether one is on file. */
  accessTokenSet: boolean
}

/**
 * Admin config follow-up to roadmap Phase 15.3: Owner-only gym-wide
 * WhatsApp switch + credential setup, reusing GymVoter::MANAGE like
 * useGymBranding does — same shape, different endpoint.
 */
export function useWhatsAppSettings() {
  const { authFetch } = useAuth()
  const [settings, setSettings] = useState<WhatsAppSettingsDto>({ enabled: false, phoneNumberId: null, accessTokenSet: false })
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<WhatsAppSettingsDto>('/gym/whatsapp-settings', { method: 'GET' })
    setSettings(data)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const updateSettings = useCallback(
    async (options: { enabled?: boolean; accessToken?: string; phoneNumberId?: string }) => {
      const updated = await authFetch<WhatsAppSettingsDto>('/gym/whatsapp-settings', { method: 'PATCH', body: options })
      setSettings(updated)

      return updated
    },
    [authFetch],
  )

  return { settings, loaded, refresh, updateSettings }
}

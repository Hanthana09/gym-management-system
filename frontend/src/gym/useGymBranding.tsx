import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'

export interface GymBrandingDto {
  name: string | null
  logoUrl: string | null
  brandColor: string | null
}

interface GymBrandingContextValue {
  branding: GymBrandingDto
  loaded: boolean
  refresh: () => Promise<void>
  updateBranding: (options: { name?: string; logo?: File; brandColor?: string }) => Promise<GymBrandingDto>
}

const GymBrandingContext = createContext<GymBrandingContextValue | null>(null)

/**
 * roadmap Phase 15.2: every authenticated role reads this (NavShell's
 * header, the Member's Badge), only the Owner can write it — the read
 * side deliberately has no role gate, matching the backend's
 * `GET /gym/branding` (functional requirements §12.1: a Member must see
 * the gym's branding too, not just the Owner who set it).
 *
 * A Context, not a plain per-component hook — NavShell, MyMembershipCard,
 * and OwnerSettingsPage all read this simultaneously (NavShell wraps
 * OwnerSettingsPage directly), so a plain `useState`-per-call-site hook
 * left OwnerSettingsPage's own successful save invisible everywhere else
 * until a hard reload remounted NavShell's separate copy. One shared
 * instance (same pattern as AuthContext, the only other cross-component
 * shared state in this app) means `updateBranding()` from anywhere is
 * visible everywhere immediately.
 */
export function GymBrandingProvider({ children }: { children: ReactNode }) {
  const { authFetch, authFetchForm, status } = useAuth()
  const [branding, setBranding] = useState<GymBrandingDto>({ name: null, logoUrl: null, brandColor: null })
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<GymBrandingDto>('/gym/branding', { method: 'GET' })
    setBranding(data)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    // Only fetch once actually signed in — this provider wraps the whole
    // app (including /login), and GET /gym/branding requires a token.
    // Re-runs on every fresh 'authenticated' transition too, so branding
    // from a previous session's Owner never leaks into the next login on
    // the same page load.
    if (status !== 'authenticated') return
    void refresh()
  }, [status, refresh])

  const updateBranding = useCallback(
    async (options: { name?: string; logo?: File; brandColor?: string }) => {
      const formData = new FormData()
      if (options.name !== undefined) formData.append('name', options.name)
      if (options.logo) formData.append('logo', options.logo)
      if (options.brandColor !== undefined) formData.append('brandColor', options.brandColor)

      const updated = await authFetchForm<GymBrandingDto>('/gym/branding', formData)
      setBranding(updated)

      return updated
    },
    [authFetchForm],
  )

  return (
    <GymBrandingContext.Provider value={{ branding, loaded, refresh, updateBranding }}>
      {children}
    </GymBrandingContext.Provider>
  )
}

export function useGymBranding(): GymBrandingContextValue {
  const context = useContext(GymBrandingContext)
  if (!context) throw new Error('useGymBranding must be used within a GymBrandingProvider')

  return context
}

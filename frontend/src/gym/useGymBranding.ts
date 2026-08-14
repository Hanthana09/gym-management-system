import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'

export interface GymBrandingDto {
  logoUrl: string | null
  brandColor: string | null
}

/**
 * roadmap Phase 15.2: every authenticated role reads this (NavShell's
 * header, the Member's Badge), only the Owner can write it — the read
 * side deliberately has no role gate, matching the backend's
 * `GET /gym/branding` (functional requirements §12.1: a Member must see
 * the gym's branding too, not just the Owner who set it).
 */
export function useGymBranding() {
  const { authFetch, authFetchForm } = useAuth()
  const [branding, setBranding] = useState<GymBrandingDto>({ logoUrl: null, brandColor: null })
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<GymBrandingDto>('/gym/branding', { method: 'GET' })
    setBranding(data)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const updateBranding = useCallback(
    async (options: { logo?: File; brandColor?: string }) => {
      const formData = new FormData()
      if (options.logo) formData.append('logo', options.logo)
      if (options.brandColor !== undefined) formData.append('brandColor', options.brandColor)

      const updated = await authFetchForm<GymBrandingDto>('/gym/branding', formData)
      setBranding(updated)

      return updated
    },
    [authFetchForm],
  )

  return { branding, loaded, refresh, updateBranding }
}

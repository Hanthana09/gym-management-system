import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'

export type MemberIdMode = 'auto' | 'manual'

export interface GymMemberIdSettingsDto {
  mode: MemberIdMode
  gymCode: string | null
}

interface GymMemberIdSettingsContextValue {
  settings: GymMemberIdSettingsDto
  loaded: boolean
  refresh: () => Promise<void>
  updateSettings: (options: { mode?: MemberIdMode; gymCode?: string }) => Promise<GymMemberIdSettingsDto>
}

const GymMemberIdSettingsContext = createContext<GymMemberIdSettingsContextValue | null>(null)

/**
 * Follow-up feature: "editable/manual Member ID mode." A Context (same
 * reasoning as GymBrandingProvider) because MemberProfileForm — used
 * from both OwnerMembersPage's "Add Member" modal and MemberDetailPage's
 * Profile tab — needs to read the gym's current mode without either
 * screen owning a duplicate copy of it. Owner-only backend
 * (`/gym/member-id-settings`), so this never fetches for Coach/Member
 * sessions — those roles never need this and would just get a 403.
 */
export function GymMemberIdSettingsProvider({ children }: { children: ReactNode }) {
  const { authFetch, status, user } = useAuth()
  const [settings, setSettings] = useState<GymMemberIdSettingsDto>({ mode: 'auto', gymCode: null })
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<GymMemberIdSettingsDto>('/gym/member-id-settings', { method: 'GET' })
    setSettings(data)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    if (status !== 'authenticated' || (user?.role !== 'owner' && user?.role !== 'staff')) return
    void refresh()
  }, [status, user?.role, refresh])

  const updateSettings = useCallback(
    async (options: { mode?: MemberIdMode; gymCode?: string }) => {
      const updated = await authFetch<GymMemberIdSettingsDto>('/gym/member-id-settings', { method: 'PATCH', body: options })
      setSettings(updated)

      return updated
    },
    [authFetch],
  )

  return (
    <GymMemberIdSettingsContext.Provider value={{ settings, loaded, refresh, updateSettings }}>
      {children}
    </GymMemberIdSettingsContext.Provider>
  )
}

export function useGymMemberIdSettings(): GymMemberIdSettingsContextValue {
  const context = useContext(GymMemberIdSettingsContext)
  if (!context) throw new Error('useGymMemberIdSettings must be used within a GymMemberIdSettingsProvider')

  return context
}

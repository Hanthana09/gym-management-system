import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ReferralCodeDto } from './types'

/** roadmap Phase 9.2 (GTM Pillar F) — Owner-only, lazily created on first fetch. */
export function useReferralCode() {
  const { authFetch } = useAuth()
  const [code, setCode] = useState<ReferralCodeDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    let cancelled = false

    authFetch<ReferralCodeDto>('/referral-code', { method: 'GET' }).then((data) => {
      if (cancelled) return
      setCode(data)
      setLoaded(true)
    })

    return () => {
      cancelled = true
    }
  }, [authFetch])

  return { code, loaded }
}

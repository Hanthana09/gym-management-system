import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { RevenueForecastDto } from './types'

/** functional requirements §10.3: 30/60/90-day projection, or an explicit "not enough data" state. */
export function useRevenueForecast(days: 30 | 60 | 90) {
  const { authFetch } = useAuth()
  const [forecast, setForecast] = useState<RevenueForecastDto | null>(null)
  const [loading, setLoading] = useState(false)

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const data = await authFetch<RevenueForecastDto>(`/reports/revenue-forecast?days=${days}`, { method: 'GET' })
      setForecast(data)
    } finally {
      setLoading(false)
    }
  }, [authFetch, days])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { forecast, loading }
}

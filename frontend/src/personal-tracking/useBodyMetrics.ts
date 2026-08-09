import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { BodyMetricDto } from './types'

/** functional requirements §7.2 — chronological (server-ordered), what the progress chart plots. */
export function useBodyMetrics() {
  const { authFetch } = useAuth()
  const [bodyMetrics, setBodyMetrics] = useState<BodyMetricDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ bodyMetrics: BodyMetricDto[] }>('/members/me/body-metrics', { method: 'GET' })
    setBodyMetrics(data.bodyMetrics)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const logBodyMetric = useCallback(
    async (weightKg: string, bodyFatPct?: string) => {
      const metric = await authFetch<BodyMetricDto>('/members/me/body-metrics', { body: { weightKg, bodyFatPct } })
      setBodyMetrics((prev) => [...prev, metric].sort((a, b) => a.date.localeCompare(b.date)))

      return metric
    },
    [authFetch],
  )

  return { bodyMetrics, loaded, logBodyMetric }
}

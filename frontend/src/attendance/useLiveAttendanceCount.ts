import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { AttendanceReportDto } from './types'

/**
 * "Owner's live counter updates in real time without a manual refresh"
 * (functional requirements §4.2 / roadmap Phase 5). Seeds from the same
 * report endpoint (called with no date range = today), then Mercure
 * pushes the authoritative count on every check-in.
 */
export function useLiveAttendanceCount() {
  const { authFetch } = useAuth()
  const [count, setCount] = useState<number | null>(null)
  const [gymId, setGymId] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    authFetch<AttendanceReportDto>('/reports/attendance', { method: 'GET' }).then((data) => {
      if (cancelled) return
      setCount(data.count)
      setGymId(data.gymId)
    })

    return () => {
      cancelled = true
    }
  }, [authFetch])

  useEffect(() => {
    if (!gymId) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `gym/${gymId}/attendance`)
    const source = new EventSource(url)

    source.onmessage = (event) => {
      const update = JSON.parse(event.data) as { count: number }
      setCount(update.count)
    }

    return () => source.close()
  }, [gymId])

  return count
}

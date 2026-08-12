import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { AttendanceEntryDto, DailyCheckinCountDto } from './types'

/** functional requirements §4.2: "filter by date range." roadmap Phase 11 §10.2: adds the per-day trend alongside the existing entry list. */
export function useAttendanceReport(from: string, to: string) {
  const { authFetch } = useAuth()
  const [entries, setEntries] = useState<AttendanceEntryDto[]>([])
  const [dailyCounts, setDailyCounts] = useState<DailyCheckinCountDto[]>([])
  const [loading, setLoading] = useState(false)

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const data = await authFetch<{ entries: AttendanceEntryDto[]; dailyCounts: DailyCheckinCountDto[] }>(
        `/reports/attendance?from=${from}&to=${to}`,
        { method: 'GET' },
      )
      setEntries(data.entries)
      setDailyCounts(data.dailyCounts)
    } finally {
      setLoading(false)
    }
  }, [authFetch, from, to])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { entries, dailyCounts, loading, refresh }
}

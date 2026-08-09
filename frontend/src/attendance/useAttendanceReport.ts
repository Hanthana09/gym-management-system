import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { AttendanceEntryDto } from './types'

/** functional requirements §4.2: "filter by date range." */
export function useAttendanceReport(from: string, to: string) {
  const { authFetch } = useAuth()
  const [entries, setEntries] = useState<AttendanceEntryDto[]>([])
  const [loading, setLoading] = useState(false)

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const data = await authFetch<{ entries: AttendanceEntryDto[] }>(
        `/reports/attendance?from=${from}&to=${to}`,
        { method: 'GET' },
      )
      setEntries(data.entries)
    } finally {
      setLoading(false)
    }
  }, [authFetch, from, to])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return { entries, loading, refresh }
}

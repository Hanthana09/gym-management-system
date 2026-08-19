import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MemberOptionDto, WorkoutAssignmentDto } from './types'

interface MemberOption extends MemberOptionDto {
  hasActiveAssignmentFromMe: boolean
}

/** setly-phase-workout-scheduling.md frontend task #2: select member, select schedule, confirm — with a replace-warning when applicable. */
export function useAssignSchedule() {
  const { authFetch } = useAuth()
  const [members, setMembers] = useState<MemberOption[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ members: MemberOption[] }>('/workout-assignments/members', { method: 'GET' })
    setMembers(data.members)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const assign = useCallback(
    (scheduleId: string, memberId: string) =>
      authFetch<WorkoutAssignmentDto>('/workout-assignments', { method: 'POST', body: { scheduleId, memberId } }),
    [authFetch],
  )

  return { members, loaded, refresh, assign }
}

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { WorkoutLogDto } from './types'

/** functional requirements §7.1 — Member's own workout history, newest first (server-ordered). */
export function useWorkouts() {
  const { authFetch } = useAuth()
  const [workouts, setWorkouts] = useState<WorkoutLogDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ workouts: WorkoutLogDto[] }>('/members/me/workouts', { method: 'GET' })
    setWorkouts(data.workouts)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const logWorkout = useCallback(
    async (type: string, durationMinutes: number) => {
      const log = await authFetch<WorkoutLogDto>('/members/me/workouts', { body: { type, durationMinutes } })
      setWorkouts((prev) => [log, ...prev])

      return log
    },
    [authFetch],
  )

  return { workouts, loaded, logWorkout }
}

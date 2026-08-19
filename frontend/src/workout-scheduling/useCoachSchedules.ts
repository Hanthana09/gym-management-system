import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { WorkoutScheduleDto, WorkoutScheduleExerciseDto } from './types'

/** setly-phase-workout-scheduling.md §7: Coach's own schedule templates + line-item CRUD. */
export function useCoachSchedules() {
  const { authFetch } = useAuth()
  const [schedules, setSchedules] = useState<WorkoutScheduleDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ schedules: WorkoutScheduleDto[] }>('/workout-schedules', { method: 'GET' })
    setSchedules(data.schedules)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createSchedule = useCallback(
    async (name: string, type: string) => {
      const created = await authFetch<WorkoutScheduleDto>('/workout-schedules', { method: 'POST', body: { name, type } })
      setSchedules((prev) => [created, ...prev])
      return created
    },
    [authFetch],
  )

  const getSchedule = useCallback(
    (id: string) => authFetch<WorkoutScheduleDto>(`/workout-schedules/${id}`, { method: 'GET' }),
    [authFetch],
  )

  const addExercise = useCallback(
    (
      scheduleId: string,
      exerciseId: string,
      dayNumber: number,
      order: number,
      sets: number,
      reps: number,
      restSeconds: number | null,
      notes: string,
    ) =>
      authFetch<WorkoutScheduleExerciseDto>(`/workout-schedules/${scheduleId}/exercises`, {
        method: 'POST',
        body: { exerciseId, dayNumber, order, sets, reps, restSeconds, notes: notes || null },
      }),
    [authFetch],
  )

  const updateExercise = useCallback(
    (lineId: string, patch: Partial<Pick<WorkoutScheduleExerciseDto, 'dayNumber' | 'order' | 'sets' | 'reps' | 'restSeconds' | 'notes'>>) =>
      authFetch<WorkoutScheduleExerciseDto>(`/workout-schedule-exercises/${lineId}`, { method: 'PATCH', body: patch }),
    [authFetch],
  )

  const removeExercise = useCallback(
    (lineId: string) => authFetch<void>(`/workout-schedule-exercises/${lineId}`, { method: 'DELETE' }),
    [authFetch],
  )

  return { schedules, loaded, refresh, createSchedule, getSchedule, addExercise, updateExercise, removeExercise }
}

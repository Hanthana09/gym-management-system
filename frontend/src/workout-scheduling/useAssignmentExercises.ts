import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MERCURE_URL } from '../lib/apiClient'
import type { ExerciseLogDto, WorkoutScheduleExerciseDto } from './types'

/**
 * setly-phase-workout-scheduling.md §7: GET /workout-assignments/{id}/exercises
 * — scoped to this assignment's schedule only, never the unscoped catalog.
 * Independently subscribes to the same Mercure topic as useMyAssignments
 * (same per-hook subscription convention as every other Mercure consumer
 * in this codebase) so a coach's live schedule edit refreshes this list
 * too, not just the assignment list.
 */
export function useAssignmentExercises(assignmentId: string | null, muscle: string, equipment: string) {
  const { authFetch, user } = useAuth()
  const [exercises, setExercises] = useState<WorkoutScheduleExerciseDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    if (!assignmentId) {
      setExercises([])
      setLoaded(true)
      return
    }
    const params = new URLSearchParams()
    if (muscle) params.set('muscle', muscle)
    if (equipment) params.set('equipment', equipment)
    const query = params.toString()
    const data = await authFetch<{ exercises: WorkoutScheduleExerciseDto[] }>(
      `/workout-assignments/${assignmentId}/exercises${query ? `?${query}` : ''}`,
      { method: 'GET' },
    )
    setExercises(data.exercises)
    setLoaded(true)
  }, [authFetch, assignmentId, muscle, equipment])

  useEffect(() => {
    setLoaded(false)
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (!user) return

    const url = new URL(MERCURE_URL)
    url.searchParams.append('topic', `members/${user.id}/assignment-updates`)
    const source = new EventSource(url)
    source.onmessage = () => {
      void refresh()
    }

    return () => source.close()
  }, [user, refresh])

  const log = useCallback(
    (exerciseId: string, setsCompleted: number, repsCompleted: number, weight: string, notes: string) =>
      authFetch<ExerciseLogDto>('/exercise-logs', {
        method: 'POST',
        body: {
          assignmentId,
          exerciseId,
          setsCompleted,
          repsCompleted,
          weight: weight || null,
          notes: notes || null,
        },
      }),
    [authFetch, assignmentId],
  )

  return { exercises, loaded, log }
}

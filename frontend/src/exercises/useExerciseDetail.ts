import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ExerciseDetailDto } from './types'

/** setly-phase-exercise-media.md §5: GET /exercises/{id} — full detail, including detailImageUrls and instructions. */
export function useExerciseDetail(id: string | null) {
  const { authFetch } = useAuth()
  const [exercise, setExercise] = useState<ExerciseDetailDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    if (!id) {
      setExercise(null)
      setLoaded(true)
      return
    }
    let cancelled = false
    setLoaded(false)
    // See useExercises.ts for why the path repeats /api/v1.
    authFetch<ExerciseDetailDto>(`/api/v1/exercises/${id}`, { method: 'GET' }).then((data) => {
      if (cancelled) return
      setExercise(data)
      setLoaded(true)
    })

    return () => {
      cancelled = true
    }
  }, [authFetch, id])

  return { exercise, loaded }
}

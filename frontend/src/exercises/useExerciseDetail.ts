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
    // See useExercises.ts — the /api/api/ double-prefix bug is fixed, so
    // authFetch's own "/api" prefix only needs /v1 appended here now.
    authFetch<ExerciseDetailDto>(`/v1/exercises/${id}`, { method: 'GET' }).then((data) => {
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

import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ExerciseListItemDto } from './types'

const ITEMS_PER_PAGE = 24

interface JsonLdCollection<T> {
  totalItems: number
  member: T[]
}

/**
 * setly-phase-exercise-media.md §5/§6: Coach's unscoped, paginated catalog
 * browse — the schedule builder's exercise picker source. Coach-created
 * custom exercises are a hard exclusion of this phase (the catalog is
 * platform-wide, written only by `app:exercise:import`), so unlike the
 * gym-scoped stopgap this replaces, there is no `create()` here anymore.
 */
export function useExercises(muscle: string, equipment: string, category: string, page: number) {
  const { authFetch } = useAuth()
  const [exercises, setExercises] = useState<ExerciseListItemDto[]>([])
  const [totalItems, setTotalItems] = useState(0)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const params = new URLSearchParams({ page: String(page), itemsPerPage: String(ITEMS_PER_PAGE) })
    if (muscle) params.set('muscle', muscle)
    if (equipment) params.set('equipment', equipment)
    if (category) params.set('category', category)

    // Exercise uses a real #[ApiResource] (not a hand-written controller,
    // unlike every other endpoint in this app) — API Platform's own
    // routing prepends /api on top of the entity's own routePrefix:
    // '/api/v1', so the full backend path is /api/api/v1/exercises;
    // authFetch already prepends API_URL ("/api"), so this path needs the
    // remaining /api/v1 segment itself.
    const data = await authFetch<JsonLdCollection<ExerciseListItemDto>>(`/api/v1/exercises?${params.toString()}`, { method: 'GET' })
    setExercises(data.member)
    setTotalItems(data.totalItems)
    setLoaded(true)
  }, [authFetch, muscle, equipment, category, page])

  useEffect(() => {
    setLoaded(false)
    void refresh()
  }, [refresh])

  return { exercises, totalItems, itemsPerPage: ITEMS_PER_PAGE, loaded }
}

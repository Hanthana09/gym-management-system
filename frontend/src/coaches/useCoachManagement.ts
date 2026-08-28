import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { CoachProfileDetailDto, CoachProfileFieldsInput, CreateCoachInput } from './types'

/**
 * gym-management-coach-management.md: Owner-only coach CRUD.
 *   - create        → POST   /coaches            (immediately-active account)
 *   - updateProfile → PATCH  /coaches/:id        (identity + specialty/bio/hourlyRate)
 *   - updateStatus  → PATCH  /coaches/:id/status (suspend / reactivate)
 */
export function useCoachManagement() {
  const { authFetch } = useAuth()

  const create = useCallback(
    (input: CreateCoachInput) => authFetch<CoachProfileDetailDto>('/coaches', { method: 'POST', body: input }),
    [authFetch],
  )

  const updateProfile = useCallback(
    (id: string, fields: CoachProfileFieldsInput) =>
      authFetch<CoachProfileDetailDto>(`/coaches/${id}`, { method: 'PATCH', body: fields }),
    [authFetch],
  )

  const updateStatus = useCallback(
    (id: string, status: 'active' | 'suspended') =>
      authFetch<CoachProfileDetailDto>(`/coaches/${id}/status`, { method: 'PATCH', body: { status } }),
    [authFetch],
  )

  return { create, updateProfile, updateStatus }
}

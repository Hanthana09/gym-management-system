import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MemberProfileDetailDto, MemberProfileFieldsInput } from './types'

export interface CreateMemberInput extends MemberProfileFieldsInput {
  name: string
  email?: string | null
  phone?: string | null
}

/**
 * gym-management-member-profile-extension.md §4: manual walk-in
 * creation — POST /members. A new pathway alongside the existing
 * invite/approve flow (untouched), not a replacement for it. Owner +
 * Staff (follow-up feature widened this from Owner-only — front-desk
 * registration is typically a Staff task).
 */
export function useCreateMember() {
  const { authFetch } = useAuth()

  const create = useCallback(
    (input: CreateMemberInput) => authFetch<MemberProfileDetailDto>('/members', { body: input }),
    [authFetch],
  )

  return { create }
}

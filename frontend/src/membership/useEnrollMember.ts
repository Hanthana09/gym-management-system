import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { MembershipDto } from './types'

/**
 * Owner enrolling an existing (already-approved) member into a plan —
 * architecture doc §7's POST /memberships. Enrolling auto-creates a
 * pending Invoice via the membership.created event (roadmap Phase 10),
 * so this is also how an Owner gets billing started for members who
 * joined without a plan yet (e.g. bulk-imported or seeded accounts).
 */
export function useEnrollMember() {
  const { authFetch } = useAuth()

  const enroll = useCallback(
    async (memberUserId: string, planId: string) => {
      return authFetch<MembershipDto>('/memberships', { body: { memberUserId, planId } })
    },
    [authFetch],
  )

  return { enroll }
}

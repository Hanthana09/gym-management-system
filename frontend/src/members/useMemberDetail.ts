import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type {
  MemberAttendancePageDto,
  MemberPaymentsStubDto,
  MemberProfileDetailDto,
  MemberPtScheduleDto,
} from './types'

/**
 * gym-management-member-profile-extension.md §5's Member Detail screen —
 * profile loads eagerly (Profile tab is the default view); PT
 * schedule/attendance/payments are fetched lazily per-tab so switching to
 * Payments never pays for an attendance-history query nobody asked for.
 */
export function useMemberDetail(memberId: string) {
  const { authFetch } = useAuth()
  const [profile, setProfile] = useState<MemberProfileDetailDto | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refreshProfile = useCallback(async () => {
    const data = await authFetch<MemberProfileDetailDto>(`/members/${memberId}`, { method: 'GET' })
    setProfile(data)
    setLoaded(true)

    return data
  }, [authFetch, memberId])

  useEffect(() => {
    void refreshProfile()
  }, [refreshProfile])

  const loadPtSchedule = useCallback(
    () => authFetch<MemberPtScheduleDto>(`/members/${memberId}/pt-schedule`, { method: 'GET' }),
    [authFetch, memberId],
  )

  const loadAttendance = useCallback(
    (page: number) => authFetch<MemberAttendancePageDto>(`/members/${memberId}/attendance?page=${page}`, { method: 'GET' }),
    [authFetch, memberId],
  )

  const loadPayments = useCallback(
    () => authFetch<MemberPaymentsStubDto>(`/members/${memberId}/payments`, { method: 'GET' }),
    [authFetch, memberId],
  )

  return { profile, loaded, refreshProfile, loadPtSchedule, loadAttendance, loadPayments }
}

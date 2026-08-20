export type MemberAccountStatus = 'pending_approval' | 'active' | 'suspended'
export type RosterRole = 'member' | 'coach'
export type Gender = 'male' | 'female' | 'other' | 'prefer_not_to_say'

export interface MemberMembershipSummary {
  planName: string
  status: string
}

export interface MemberListItemDto {
  id: string
  // gym-management-member-profile-extension.md §3 — null only for a
  // not-yet-backfilled pre-existing row; every member created from here
  // on has one immediately.
  memberId: string | null
  name: string
  email: string | null
  phone: string | null
  role: RosterRole
  status: MemberAccountStatus
  joinedAt: string
  // Coaches never have a Membership — always null for role: 'coach'.
  membership: MemberMembershipSummary | null
  // A Member's enrolling branch (0 or 1 entries) or a Coach's assigned
  // branches (0+ entries) — what the Owner roster's branch filter matches
  // against. Never a restriction on the Member themselves (hub model).
  branchIds: string[]
}

/** GET/PATCH /members/:id — Owner/Staff (any member) or a Member reading their own record. */
export interface MemberProfileDetailDto {
  id: string
  memberId: string | null
  name: string
  email: string | null
  phone: string | null
  status: MemberAccountStatus
  joinedAt: string
  membership: MemberMembershipSummary | null
  dob: string | null
  age: number | null
  gender: Gender | null
  addressLine: string | null
  addressCity: string | null
  addressPostalCode: string | null
}

/**
 * The subset of profile fields writable via PATCH /members/:id or
 * POST /members. `memberId` only has any effect for a gym in manual
 * Member ID mode — sending it for an auto-mode gym is rejected by the
 * backend (422), so the frontend only ever includes this key when
 * MemberProfileForm rendered the field at all.
 */
export interface MemberProfileFieldsInput {
  dob?: string | null
  gender?: Gender | null
  addressLine?: string | null
  addressCity?: string | null
  addressPostalCode?: string | null
  memberId?: string
}

export interface PtSessionScheduleDto {
  id: string
  coachName: string
  scheduledAt: string
  durationMinutes: number
  status: string
}

export interface WorkoutAssignmentScheduleDto {
  id: string
  scheduleName: string
  coachName: string
  status: string
  startDate: string
  assignedAt: string
}

export interface MemberPtScheduleDto {
  ptSessions: PtSessionScheduleDto[]
  workoutAssignments: WorkoutAssignmentScheduleDto[]
}

export interface AttendanceLogDto {
  id: string
  checkIn: string
  checkOut: string | null
  branchName: string
  method: string
}

export interface MemberAttendancePageDto {
  logs: AttendanceLogDto[]
  page: number
  perPage: number
  total: number
}

/** Payments tab — always this stub shape until Phase 10 ships real billing. `available: false` renders an explicit empty-state, never an empty list pretending to be real data. */
export interface MemberPaymentsStubDto {
  available: false
  reason: 'not_yet_available'
  message: string
  payments: never[]
}

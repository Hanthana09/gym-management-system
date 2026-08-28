/**
 * gym-management-coach-management.md: coach CRUD for an Owner. The
 * `/coaches/:id` detail shape (CoachController) — distinct from
 * personal-training's `CoachDto` ({id, name} only, for the Member's
 * booking picker) and from the roster's `MemberListItemDto` (role:
 * 'coach' rows carry no profile fields).
 */
export type CoachAccountStatus = 'pending_approval' | 'active' | 'suspended'

export interface CoachBranchSummary {
  id: string
  name: string
}

export interface CoachProfileDetailDto {
  id: string
  name: string
  email: string | null
  phone: string | null
  role: 'coach'
  status: CoachAccountStatus
  joinedAt: string
  specialty: string | null
  bio: string | null
  /** decimal string, e.g. "55.00" — used by the FinancialSummary PT-revenue estimate (architecture §6.13). */
  hourlyRate: string | null
  branchIds: string[]
  branches: CoachBranchSummary[]
}

/** Writable fields on POST /coaches and PATCH /coaches/:id. */
export interface CoachProfileFieldsInput {
  name?: string
  email?: string | null
  phone?: string | null
  specialty?: string | null
  bio?: string | null
  hourlyRate?: string | null
}

export interface CreateCoachInput extends CoachProfileFieldsInput {
  name: string
}

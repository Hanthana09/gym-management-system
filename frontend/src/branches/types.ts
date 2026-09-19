export type BranchStatus = 'active' | 'inactive'

export interface BranchAssignmentDto {
  userId: string
  name: string
  role: 'coach' | 'staff'
}

/** A Member's Owner-set "home branch" tag — separate from BranchAssignmentDto above (Coach/Staff access-scoping); purely informational, doesn't restrict check-in. */
export interface BranchMemberAssignmentDto {
  userId: string
  name: string
}

export interface BranchDto {
  id: string
  name: string
  address: string
  phone: string | null
  isPrimary: boolean
  status: BranchStatus
  assignments: BranchAssignmentDto[]
  memberAssignments: BranchMemberAssignmentDto[]
}

export interface AssignableUserDto {
  id: string
  name: string
  role: 'coach' | 'staff'
}

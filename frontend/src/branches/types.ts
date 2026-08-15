export type BranchStatus = 'active' | 'inactive'

export interface BranchAssignmentDto {
  userId: string
  name: string
  role: 'coach' | 'staff'
}

export interface BranchDto {
  id: string
  name: string
  address: string
  phone: string | null
  isPrimary: boolean
  status: BranchStatus
  assignments: BranchAssignmentDto[]
}

export interface AssignableUserDto {
  id: string
  name: string
  role: 'coach' | 'staff'
}

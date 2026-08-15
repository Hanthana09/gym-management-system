export type MemberAccountStatus = 'pending_approval' | 'active' | 'suspended'
export type RosterRole = 'member' | 'coach'

export interface MemberMembershipSummary {
  planName: string
  status: string
}

export interface MemberListItemDto {
  id: string
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

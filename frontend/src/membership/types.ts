export type MembershipStatus = 'active' | 'paused' | 'expired' | 'cancelled'

export interface MembershipPlanDto {
  id: string
  name: string
  price: string
  durationDays: number
  features: string[]
}

export interface MembershipDto {
  id: string
  plan: MembershipPlanDto
  startDate: string
  endDate: string
  status: MembershipStatus
  autoRenew: boolean
  daysUntilExpiry: number
}

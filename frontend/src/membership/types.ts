export type MembershipStatus = 'active' | 'paused' | 'suspended' | 'expired' | 'cancelled'

/**
 * Mirrors Membership::isOngoing() on the backend — active/paused/
 * suspended still "hold the slot," expired/cancelled don't. A member's
 * `membership` summary is their most recent one regardless of status
 * (MembershipService::getMembershipForMember()), so an expired/cancelled
 * membership still shows up here; the Owner's "assign plan" actions use
 * this to tell "change the existing plan" (PATCH .../plan, ongoing only)
 * apart from "enroll fresh" (POST /memberships, the expired/cancelled
 * case) rather than assuming "has a membership" means "can change it."
 */
export function isOngoingMembershipStatus(status: string): boolean {
  return status === 'active' || status === 'paused' || status === 'suspended'
}

export interface MembershipPlanDto {
  id: string
  branchId: string
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

export type InvitationRole = 'coach' | 'staff' | 'member'
export type InvitationStatus = 'pending' | 'approved' | 'declined' | 'expired' | 'cancelled'

export interface InvitationDto {
  id: string
  gymId: string
  destination: string
  role: InvitationRole
  status: InvitationStatus
  createdAt: string
  expiresAt: string
  respondedAt: string | null
}

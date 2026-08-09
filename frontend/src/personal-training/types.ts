export type PtSessionStatus = 'pending' | 'confirmed' | 'completed' | 'declined' | 'cancelled'

export interface PtSessionPartyDto {
  id: string
  name: string
}

export interface PtSessionDto {
  id: string
  coach: PtSessionPartyDto
  member: PtSessionPartyDto
  scheduledAt: string
  durationMinutes: number
  status: PtSessionStatus
  /** Coach-only (functional requirements §5.3) — always null in a Member's own list. */
  notes: string | null
}

export interface CoachDto {
  id: string
  name: string
}

export type ReferralLeadStatus = 'new' | 'contacted' | 'converted' | 'declined'

export interface ReferralLeadDto {
  id: string
  prospectGymName: string
  contactName: string | null
  contactEmail: string | null
  contactPhone: string | null
  status: ReferralLeadStatus
  createdAt: string
}

export interface ReferralCodeDto {
  code: string
  usageCount: number
  createdAt: string
}

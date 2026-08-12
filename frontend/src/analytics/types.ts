export interface DashboardSummaryDto {
  gymId: string
  todayCheckins: number
  todayRevenue: string
  activeMembersCount: number
}

export interface RevenueForecastPointDto {
  date: string
  revenue: string
}

export interface RevenueForecastDto {
  hasEnoughData: boolean
  historical: RevenueForecastPointDto[]
  projected: RevenueForecastPointDto[]
  method: string | null
}

export interface RetentionMemberDto {
  memberId: string
  memberName: string
  reasons: string[]
}

export type ExportReportType = 'dashboard' | 'attendance' | 'revenue' | 'retention'
export type ExportFormat = 'csv' | 'pdf'

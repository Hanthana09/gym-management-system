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

// ---- Home-dashboard chart widgets (Owner analytics slice, Phase 11) -------
// One DTO per `/api/v1/analytics/*` endpoint (see backend AnalyticsController).
// Every response also carries `gymId` and `branchId` (echoed back); the
// widgets don't need them, so they're omitted from these shapes.

export type RevenueGranularity = 'daily' | 'monthly'

export interface RevenuePointDto {
  /** 'YYYY-MM-DD' for daily, 'YYYY-MM' for monthly. */
  period: string
  /** Decimal string, 2dp, e.g. '1234.00'. */
  revenue: string
}

export interface RevenueTrendDto {
  granularity: RevenueGranularity
  from: string
  to: string
  series: RevenuePointDto[]
}

/** No `frozen` — this system has no such status (see backend note). */
export interface MembershipHealthDto {
  asOf: string
  buckets: {
    active: number
    expiring: number
    expired: number
    paused: number
    suspended: number
    cancelled: number
  }
}

export type MembershipHealthBucket = keyof MembershipHealthDto['buckets']

export interface PeakHoursCellDto {
  /** 0 = Sunday … 6 = Saturday. */
  dayOfWeek: number
  /** 0–23. */
  hour: number
  count: number
}

export interface PeakHoursDto {
  windowDays: number
  maxCount: number
  totalCheckins: number
  grid: PeakHoursCellDto[]
}

export interface BranchComparisonRowDto {
  branchId: string
  branchName: string
  revenue: string
  attendanceCount: number
  activeMembers: number
}

export interface BranchComparisonDto {
  period: string
  from: string
  to: string
  branches: BranchComparisonRowDto[]
}

export interface AtRiskTrendPointDto {
  weekEnding: string
  count: number
}

export interface AtRiskTrendDto {
  weeks: number
  trend: AtRiskTrendPointDto[]
  current: number
}

export interface NewVsReturningPointDto {
  /** 'YYYY-MM'. */
  period: string
  new: number
  returning: number
}

export interface NewVsReturningDto {
  granularity: 'monthly'
  from: string
  to: string
  series: NewVsReturningPointDto[]
}

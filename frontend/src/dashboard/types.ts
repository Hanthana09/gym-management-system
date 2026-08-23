export interface BranchRef {
  id: string
  name: string
}

export interface OwnerDashboardDto {
  branchId: string | null
  todayCheckins: number
  todayRevenue: string
  activeMembersCount: number
  unreadNotificationCount: number
}

export interface ExpiringMembershipDto {
  memberId: string
  memberName: string
  endDate: string
}

export interface StaffActivityEntryDto {
  id: string
  memberName: string
  checkInAt: string
}

export interface StaffDashboardDto {
  branchId: string
  todayCheckins: number
  expiringMembershipsCount: number
  expiringMemberships: ExpiringMembershipDto[]
  recentActivity: StaffActivityEntryDto[]
  unreadNotificationCount: number
}

export interface DashboardSessionDto {
  id: string
  memberName: string
  scheduledAt: string
  durationMinutes: number
  status: string
  branch: BranchRef
}

export interface CoachDashboardDto {
  branchId: string
  todaySessions: DashboardSessionDto[]
  assignedMembersCount: number
  weeklyUtilization: {
    sessionsThisWeek: number
    percentage: number
  }
  unreadNotificationCount: number
}

export interface MemberAttendanceEntryDto {
  id: string
  checkInAt: string
  branch: BranchRef
}

export interface MemberDashboardDto {
  nextSession: DashboardSessionDto | null
  recentAttendance: MemberAttendanceEntryDto[]
  unreadNotificationCount: number
}

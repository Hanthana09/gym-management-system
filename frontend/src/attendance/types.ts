export interface AttendanceEntryDto {
  id: string
  memberName: string
  checkInAt: string
  method: string
}

export interface AttendanceReportDto {
  gymId: string
  count: number
  entries: AttendanceEntryDto[]
}

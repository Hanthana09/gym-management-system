export type WorkoutScheduleStatus = 'draft' | 'active' | 'archived'
export type WorkoutAssignmentStatus = 'active' | 'replaced' | 'completed' | 'cancelled'

export interface WorkoutScheduleExerciseDto {
  id: string
  scheduleId?: string
  exerciseId: string
  exerciseName: string
  /** Only present on the member-facing scoped endpoint (GET /workout-assignments/{id}/exercises) — the coach builder's own line-item response doesn't include catalog fields. */
  primaryMuscles?: string[]
  equipment?: string | null
  posterUrl?: string | null
  dayNumber: number
  order: number
  sets: number
  reps: number
  restSeconds: number | null
  notes: string | null
}

export interface WorkoutScheduleDto {
  id: string
  coachId: string
  name: string
  type: string
  status: WorkoutScheduleStatus
  createdAt: string
  updatedAt: string
  exercises?: WorkoutScheduleExerciseDto[]
}

export interface WorkoutAssignmentDto {
  id: string
  scheduleId: string
  scheduleName: string
  memberId: string
  coachId: string
  coachName: string
  status: WorkoutAssignmentStatus
  startDate: string
  assignedAt: string
}

export interface ExerciseLogDto {
  id: string
  exerciseId: string
  exerciseName: string
  loggedAt: string
  setsCompleted: number
  repsCompleted: number
  weight: string | null
  notes: string | null
}

export interface MemberOptionDto {
  id: string
  name: string
}

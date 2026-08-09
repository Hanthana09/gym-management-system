export interface WorkoutLogDto {
  id: string
  date: string
  type: string
  durationMinutes: number
  metrics: Record<string, unknown>
}

export interface BodyMetricDto {
  id: string
  date: string
  weightKg: string
  bodyFatPct: string | null
}

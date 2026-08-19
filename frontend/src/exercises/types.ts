/** setly-phase-exercise-media.md §5: exercise:list serialization group — never includes detail-only fields. */
export interface ExerciseListItemDto {
  id: string
  name: string
  slug: string
  category: string
  equipment: string | null
  posterUrl: string | null
}

/** exercise:detail serialization group — full record, including muscle arrays, instructions, and detail images. */
export interface ExerciseDetailDto {
  id: string
  name: string
  slug: string
  force: string | null
  level: string
  mechanic: string | null
  equipment: string | null
  primaryMuscles: string[]
  secondaryMuscles: string[]
  instructions: string[]
  category: string
  posterUrl: string | null
  detailImageUrls: string[]
}

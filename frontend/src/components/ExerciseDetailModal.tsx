import { useState } from 'react'
import { Modal } from './ui'
import { useExerciseDetail } from '../exercises/useExerciseDetail'

interface ExerciseDetailModalProps {
  exerciseId: string | null
  onClose: () => void
}

/**
 * claude-code-prompt-exercise-media.md frontend task 2: loads
 * GET /exercises/{id}, tap-to-toggle between detailImageUrls (not a
 * carousel library — there are usually only 1-2 images, matching the
 * source dataset's own start/end-position pair), instructions as a
 * numbered list.
 */
export function ExerciseDetailModal({ exerciseId, onClose }: ExerciseDetailModalProps) {
  const { exercise, loaded } = useExerciseDetail(exerciseId)
  const [imageIndex, setImageIndex] = useState(0)

  return (
    <Modal open={exerciseId !== null} onClose={onClose} title={exercise?.name ?? 'Exercise'}>
      {!loaded ? (
        <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
      ) : !exercise ? (
        <p className="py-6 text-center text-sm text-ink-soft">Exercise not found.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {exercise.detailImageUrls.length > 0 ? (
            <button
              type="button"
              onClick={() => setImageIndex((i) => (i + 1) % exercise.detailImageUrls.length)}
              className="aspect-square w-full overflow-hidden rounded-lg border border-line bg-paper-dim focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
              aria-label={exercise.detailImageUrls.length > 1 ? 'Tap to toggle position' : undefined}
            >
              <img src={exercise.detailImageUrls[imageIndex]} alt="" className="h-full w-full object-cover" />
            </button>
          ) : null}

          <div className="flex flex-wrap gap-1.5">
            {exercise.primaryMuscles.map((muscle) => (
              <span key={muscle} className="rounded-full bg-paper-dim px-2 py-0.5 font-mono text-xs tracking-wide text-ink uppercase">
                {muscle}
              </span>
            ))}
            {exercise.secondaryMuscles.map((muscle) => (
              <span key={muscle} className="rounded-full bg-paper-dim px-2 py-0.5 font-mono text-xs tracking-wide text-ink-soft uppercase">
                {muscle}
              </span>
            ))}
          </div>

          {exercise.instructions.length > 0 ? (
            <ol className="flex list-decimal flex-col gap-2 pl-5 text-sm text-ink">
              {exercise.instructions.map((step, index) => (
                <li key={index}>{step}</li>
              ))}
            </ol>
          ) : null}
        </div>
      )}
    </Modal>
  )
}

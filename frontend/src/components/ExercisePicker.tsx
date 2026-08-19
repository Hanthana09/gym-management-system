import { useEffect, useRef, useState } from 'react'
import { Input, Pagination } from './ui'

export interface PickerItem {
  id: string
  name: string
  category: string
  equipment: string | null
  posterUrl: string | null
}

interface ExercisePickerProps {
  items: PickerItem[]
  loaded: boolean
  onSelect: (item: PickerItem) => void
  selectedId?: string | null
  muscleFilter: string
  onMuscleFilterChange: (value: string) => void
  equipmentFilter: string
  onEquipmentFilterChange: (value: string) => void
  /** Omit for a small, already-fully-loaded list (e.g. a member's scoped schedule exercises) — pagination only renders when provided. */
  pagination?: { page: number; totalItems: number; itemsPerPage: number; onPageChange: (page: number) => void }
  /** Omit to skip the per-tile "Details" affordance (ExerciseDetailModal) entirely. */
  onViewDetails?: (item: PickerItem) => void
  emptyMessage?: string
}

/**
 * setly-phase-exercise-media.md §6 / claude-code-prompt-exercise-media.md
 * frontend task 1: one picker, reused unmodified by the coach's unscoped
 * catalog (useExercises) and — once the workout-scheduling phase is
 * updated to match this phase's Exercise shape — the member's assignment-
 * scoped catalog. Paginated (not virtualized: no virtualization library
 * exists in this codebase yet, and this project's own `Pagination`
 * primitive + API Platform's already-enabled pagination cover a ~500-row
 * catalog without adding one) + IntersectionObserver-driven lazy poster
 * loading (bandwidth-conscious §2's low-connectivity launch market —
 * deliberately a real IntersectionObserver, not just the native
 * `loading="lazy"` attribute, per this phase's explicit task list).
 */
export function ExercisePicker({
  items,
  loaded,
  onSelect,
  selectedId,
  muscleFilter,
  onMuscleFilterChange,
  equipmentFilter,
  onEquipmentFilterChange,
  pagination,
  onViewDetails,
  emptyMessage = 'No exercises found.',
}: ExercisePickerProps) {
  const pageCount = pagination ? Math.max(1, Math.ceil(pagination.totalItems / pagination.itemsPerPage)) : 1
  const rangeStart = !pagination || pagination.totalItems === 0 ? 0 : (pagination.page - 1) * pagination.itemsPerPage + 1
  const rangeEnd = pagination ? Math.min(pagination.totalItems, pagination.page * pagination.itemsPerPage) : 0

  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-2 gap-3">
        <Input
          label="Muscle group"
          value={muscleFilter}
          onChange={(event) => onMuscleFilterChange(event.target.value)}
          placeholder="e.g. chest"
        />
        <Input
          label="Equipment"
          value={equipmentFilter}
          onChange={(event) => onEquipmentFilterChange(event.target.value)}
          placeholder="e.g. barbell"
        />
      </div>

      {!loaded ? (
        <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
      ) : items.length === 0 ? (
        <p className="py-6 text-center text-sm text-ink-soft">{emptyMessage}</p>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {items.map((item) => (
              <PickerTile
                key={item.id}
                item={item}
                selected={selectedId === item.id}
                onSelect={onSelect}
                onViewDetails={onViewDetails}
              />
            ))}
          </div>
          {pagination ? (
            <Pagination
              page={pagination.page}
              pageCount={pageCount}
              rangeStart={rangeStart}
              rangeEnd={rangeEnd}
              total={pagination.totalItems}
              onChange={pagination.onPageChange}
            />
          ) : null}
        </>
      )}
    </div>
  )
}

interface PickerTileProps {
  item: PickerItem
  selected: boolean
  onSelect: (item: PickerItem) => void
  onViewDetails?: (item: PickerItem) => void
}

function PickerTile({ item, selected, onSelect, onViewDetails }: PickerTileProps) {
  const ref = useRef<HTMLDivElement>(null)
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const node = ref.current
    if (!node || visible) return

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setVisible(true)
          observer.disconnect()
        }
      },
      { rootMargin: '200px' },
    )
    observer.observe(node)

    return () => observer.disconnect()
  }, [visible])

  return (
    <div
      ref={ref}
      className={`relative flex min-h-touch flex-col overflow-hidden rounded-lg border bg-card transition-colors ${
        selected ? 'border-ink' : 'border-line hover:border-ink/40'
      }`}
    >
      <button
        type="button"
        onClick={() => onSelect(item)}
        className="flex flex-col text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
      >
        <div className="aspect-square w-full bg-paper-dim">
          {visible && item.posterUrl ? (
            <img src={item.posterUrl} alt="" className="h-full w-full object-cover" />
          ) : !item.posterUrl && visible ? (
            <div className="flex h-full w-full items-center justify-center text-xs text-ink-soft">No image</div>
          ) : null}
        </div>
        <div className="p-2">
          <p className="truncate text-sm font-medium text-ink">{item.name}</p>
          <p className="truncate font-mono text-xs text-ink-soft uppercase">
            {item.category}
            {item.equipment ? ` · ${item.equipment}` : ''}
          </p>
        </div>
      </button>

      {onViewDetails ? (
        <button
          type="button"
          onClick={() => onViewDetails(item)}
          aria-label={`View details for ${item.name}`}
          className="absolute top-1.5 right-1.5 flex h-6 w-6 items-center justify-center rounded-full bg-card/90 text-xs font-semibold text-ink-soft shadow-sm hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
        >
          i
        </button>
      ) : null}
    </div>
  )
}

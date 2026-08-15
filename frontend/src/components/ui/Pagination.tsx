import { Button } from './Button'

interface PaginationProps {
  page: number
  pageCount: number
  rangeStart: number
  rangeEnd: number
  total: number
  onChange: (page: number) => void
}

/** Prev/Next + "X–Y of Z" range — same shape as OwnerMembersPage's original inline pagination, extracted for reuse. Renders nothing when there's nothing to page through. */
export function Pagination({ page, pageCount, rangeStart, rangeEnd, total, onChange }: PaginationProps) {
  if (total === 0) return null

  return (
    <div className="flex items-center justify-between gap-3">
      <p className="text-sm text-ink-soft">
        {rangeStart}–{rangeEnd} of {total}
      </p>
      <div className="flex items-center gap-2">
        <Button variant="secondary" onClick={() => onChange(Math.max(1, page - 1))} disabled={page <= 1}>
          Prev
        </Button>
        <p className="text-sm text-ink-soft">
          Page {page} of {pageCount}
        </p>
        <Button variant="secondary" onClick={() => onChange(Math.min(pageCount, page + 1))} disabled={page >= pageCount}>
          Next
        </Button>
      </div>
    </div>
  )
}

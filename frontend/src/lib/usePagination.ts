import { useState } from 'react'

/**
 * Client-side pagination over an already-fetched array — for lists like
 * the Owner's attendance entries or at-risk members that come back as one
 * full array from the API, not a paginated endpoint. Extracted out of
 * OwnerMembersPage's original inline version once a second and third call
 * site needed the identical slicing math (Owner dashboard's Attendance
 * and At-risk members tabs).
 */
export function usePagination<T>(items: T[], pageSize: number) {
  const [page, setPage] = useState(1)
  const pageCount = Math.max(1, Math.ceil(items.length / pageSize))
  const currentPage = Math.min(page, pageCount)
  const paged = items.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const rangeStart = items.length === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const rangeEnd = Math.min(currentPage * pageSize, items.length)

  return { page: currentPage, pageCount, paged, rangeStart, rangeEnd, total: items.length, setPage }
}

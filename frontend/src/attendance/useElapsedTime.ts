import { useEffect, useState } from 'react'

/**
 * Ticks every second while checked in, freezes once checkOutAt is set.
 * Always recomputed from the wall clock against checkInAt/checkOutAt —
 * never accumulated client-side state — so a page refresh (or a tab that
 * was backgrounded and had its timer throttled) still shows the correct
 * elapsed time, not a stale or drifted one.
 */
export function useElapsedTime(checkInAt: string | null, checkOutAt: string | null): number {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (checkInAt === null || checkOutAt !== null) return

    // Re-sync immediately on (re)start — no 1-second wait for the first
    // tick before "00:00" becomes correct.
    setNow(Date.now())
    const id = setInterval(() => setNow(Date.now()), 1000)

    return () => clearInterval(id)
  }, [checkInAt, checkOutAt])

  if (checkInAt === null) return 0

  const end = checkOutAt !== null ? new Date(checkOutAt).getTime() : now

  return Math.max(0, end - new Date(checkInAt).getTime())
}

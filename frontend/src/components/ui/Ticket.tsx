import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

interface TicketProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode
}

/**
 * DESIGN-SYSTEM.md §3/§4 — "one badge, every station": Ticket is for
 * anything representing a discrete, scannable "event" (attendance rows,
 * PT session rows, invitation rows), not a decorative style used
 * everywhere. Two "punch holes" (bg-paper circles with a dashed ring,
 * matching the page background so they read as cut-outs) straddle the
 * vertical midpoint of the left/right edges — half in, half out, so they
 * look bitten into the card rather than clipped. Don't add
 * `overflow-hidden` here or that illusion breaks.
 */
export function Ticket({ children, className, ...rest }: TicketProps) {
  return (
    <div
      className={cn('relative rounded-xl border-2 border-dashed border-line bg-card p-4', className)}
      {...rest}
    >
      <span
        aria-hidden="true"
        className="absolute top-1/2 -left-2.5 h-5 w-5 -translate-y-1/2 rounded-full border-2 border-dashed border-line bg-paper"
      />
      <span
        aria-hidden="true"
        className="absolute top-1/2 -right-2.5 h-5 w-5 -translate-y-1/2 rounded-full border-2 border-dashed border-line bg-paper"
      />
      {children}
    </div>
  )
}

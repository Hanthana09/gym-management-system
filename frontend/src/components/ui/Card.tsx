import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  title?: string
  children: ReactNode
}

// DESIGN-SYSTEM.md §3: Card = `bg-card border border-line rounded` (kept
// at rounded-lg/shadow-sm here to match the existing spacing scale rather
// than the doc's literal shorthand).
export function Card({ title, children, className, ...rest }: CardProps) {
  return (
    <div
      className={cn(
        'rounded-lg border border-line bg-card p-4 shadow-sm sm:p-6',
        className,
      )}
      {...rest}
    >
      {title ? <h2 className="mb-3 text-base font-semibold text-ink">{title}</h2> : null}
      {children}
    </div>
  )
}

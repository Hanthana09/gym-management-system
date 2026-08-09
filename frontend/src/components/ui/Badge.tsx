import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

type BadgeRole = 'owner' | 'coach' | 'member'

interface BadgeProps extends HTMLAttributes<HTMLDivElement> {
  role: BadgeRole
  name: string
  badgeNumber: string
  children?: ReactNode
}

const STRIPE_CLASSES: Record<BadgeRole, string> = {
  owner: 'bg-owner',
  coach: 'bg-coach',
  member: 'bg-member',
}

const AVATAR_CLASSES: Record<BadgeRole, string> = {
  owner: 'bg-owner-soft text-owner',
  coach: 'bg-coach-soft text-coach',
  member: 'bg-member-soft text-member',
}

function initialsFor(name: string): string {
  const parts = name.trim().split(/\s+/)

  return parts
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/**
 * DESIGN-SYSTEM.md §3/§4: the "one badge, every station" motif — an ID
 * card, not a generic panel. Deliberately used on exactly one screen
 * (Member's "My membership", roadmap Phase 4) so it keeps feeling like a
 * single special object rather than a reused card style.
 */
export function Badge({ role, name, badgeNumber, children, className, ...rest }: BadgeProps) {
  return (
    <div className={cn('overflow-hidden rounded-xl bg-card shadow-sm', className)} {...rest}>
      <div className={cn('h-2.5 w-full', STRIPE_CLASSES[role])} aria-hidden="true" />
      <div className="p-4 sm:p-6">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <span
              className={cn(
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                AVATAR_CLASSES[role],
              )}
              aria-hidden="true"
            >
              {initialsFor(name)}
            </span>
            <div>
              <p className="text-sm font-semibold text-ink">{name}</p>
              <p className="text-xs tracking-wide text-ink-soft capitalize">{role}</p>
            </div>
          </div>
          <span className="font-mono text-xs text-ink-soft">{badgeNumber}</span>
        </div>

        {children ? <div className="mt-4">{children}</div> : null}
      </div>
    </div>
  )
}

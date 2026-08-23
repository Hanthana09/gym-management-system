import type { ReactNode } from 'react'
import { cn } from '../../lib/cn'

type AlertTone = 'warning' | 'danger'

interface AlertCardProps {
  tone?: AlertTone
  title: string
  children?: ReactNode
}

const TONE_CLASSES: Record<AlertTone, string> = {
  warning: 'border-amber-200 bg-amber-50 text-amber-900',
  danger: 'border-red-200 bg-red-50 text-red-900',
}

/**
 * gym-management-dashboard-redesign.md Phase 2: the shared "something
 * needs attention" card (e.g. Staff's expiring-memberships widget) —
 * role-agnostic, just tone/title/content. Not the same as a form-field
 * error message (that stays inline, per existing Input pattern) — this
 * is a dashboard-level surfaced condition.
 */
export function AlertCard({ tone = 'warning', title, children }: AlertCardProps) {
  return (
    <div className={cn('rounded-md border p-4', TONE_CLASSES[tone])}>
      <p className="text-sm font-semibold">{title}</p>
      {children ? <div className="mt-2 text-sm">{children}</div> : null}
    </div>
  )
}

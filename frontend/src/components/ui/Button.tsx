import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'hivis'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  fullWidth?: boolean
  /** Icon-only buttons (e.g. hamburger, close) skip horizontal padding but keep the 44px min-width. */
  iconOnly?: boolean
  children: ReactNode
}

// DESIGN-SYSTEM.md §3: primary = `bg-ink text-paper`; hivis is the one
// emphasized action per screen (e.g. check-in), never a second bright
// color alongside it.
const variantClasses: Record<ButtonVariant, string> = {
  primary: 'bg-ink text-paper hover:bg-ink/90 active:bg-ink',
  secondary: 'bg-card text-ink border border-line hover:bg-paper-dim active:bg-paper-dim',
  ghost: 'bg-transparent text-ink hover:bg-paper-dim active:bg-line/40',
  danger: 'bg-red-600 text-white hover:bg-red-700 active:bg-red-800',
  hivis: 'bg-hivis text-ink font-semibold hover:bg-hivis/90 active:bg-hivis/80',
}

export function Button({
  variant = 'primary',
  fullWidth = false,
  iconOnly = false,
  className,
  children,
  ...rest
}: ButtonProps) {
  return (
    <button
      className={cn(
        // 44x44 minimum touch target (roadmap Phase 1)
        'inline-flex min-h-touch items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-50',
        iconOnly ? 'min-w-touch px-0' : 'px-4',
        fullWidth && 'w-full',
        variantClasses[variant],
        className,
      )}
      {...rest}
    >
      {children}
    </button>
  )
}

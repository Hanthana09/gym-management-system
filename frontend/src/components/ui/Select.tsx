import { useId, type SelectHTMLAttributes } from 'react'
import { cn } from '../../lib/cn'

interface SelectOption {
  value: string
  label: string
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  options: SelectOption[]
  error?: string
  hint?: string
}

export function Select({ label, options, error, hint, id, className, ...rest }: SelectProps) {
  const generatedId = useId()
  const selectId = id ?? generatedId
  const hintId = hint ? `${selectId}-hint` : undefined
  const errorId = error ? `${selectId}-error` : undefined

  return (
    <div className="w-full">
      <label htmlFor={selectId} className="mb-1.5 block text-sm font-medium text-ink">
        {label}
      </label>
      {/* Native <select> — better mobile keyboard/UX than a custom listbox (roadmap Phase 1) */}
      <select
        id={selectId}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId ?? hintId}
        className={cn(
          'min-h-touch w-full rounded-md border bg-card px-3 text-base text-ink',
          'focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1',
          error ? 'border-red-500' : 'border-line',
          className,
        )}
        {...rest}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {error ? (
        <p id={errorId} className="mt-1.5 text-sm text-red-600">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="mt-1.5 text-sm text-ink-soft">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

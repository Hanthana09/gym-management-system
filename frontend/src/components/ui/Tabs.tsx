import { cn } from '../../lib/cn'

export interface TabItem {
  value: string
  label: string
}

interface TabsProps {
  items: TabItem[]
  value: string
  onChange: (value: string) => void
  className?: string
}

/**
 * DESIGN-SYSTEM.md §3's nav active-state rule — a border accent, not a
 * filled background — adapted from NavShell's own TabLink (bottom nav)
 * into a horizontal in-page tab strip. Scrolls rather than wraps on
 * narrow viewports (roadmap's "no horizontal page overflow" rule stays
 * intact since only this strip scrolls, not the page).
 */
export function Tabs({ items, value, onChange, className }: TabsProps) {
  return (
    <div role="tablist" className={cn('flex gap-1 overflow-x-auto border-b border-line', className)}>
      {items.map((item) => (
        <button
          key={item.value}
          type="button"
          role="tab"
          aria-selected={item.value === value}
          onClick={() => onChange(item.value)}
          className={cn(
            'min-h-touch shrink-0 border-b-[3px] px-3 text-sm whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ink',
            item.value === value
              ? 'border-ink font-semibold text-ink'
              : 'border-transparent font-medium text-ink-soft hover:text-ink',
          )}
        >
          {item.label}
        </button>
      ))}
    </div>
  )
}

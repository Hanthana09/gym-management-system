export interface ActivityFeedItem {
  id: string
  label: string
  timestamp: string
  /** A small muted tag after the label — e.g. Member's branch label (Phase 4). */
  tag?: string
}

interface ActivityFeedProps {
  items: ActivityFeedItem[]
  emptyMessage?: string
}

/**
 * gym-management-dashboard-redesign.md Phase 2: a simple recent-activity
 * list — role-agnostic, just an array of {id, label, timestamp, tag?}.
 * Used for Staff's "recent check-ins" and reused for Member's attendance
 * rows (Phase 4's per-row branch label is exactly what `tag` is for).
 */
export function ActivityFeed({ items, emptyMessage = 'Nothing yet.' }: ActivityFeedProps) {
  if (items.length === 0) {
    return <p className="py-6 text-center text-sm text-ink-soft">{emptyMessage}</p>
  }

  return (
    <ul className="flex flex-col gap-2">
      {items.map((item) => (
        <li key={item.id} className="flex items-center justify-between gap-3 rounded-md border border-line bg-card px-3 py-2">
          <div className="flex min-w-0 items-center gap-2">
            <span className="truncate text-sm text-ink">{item.label}</span>
            {item.tag ? (
              <span className="shrink-0 rounded-full bg-paper-dim px-2 py-0.5 font-mono text-xs tracking-wide text-ink-soft uppercase">
                {item.tag}
              </span>
            ) : null}
          </div>
          <span className="shrink-0 font-mono text-xs text-ink-soft">
            {new Date(item.timestamp).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
          </span>
        </li>
      ))}
    </ul>
  )
}

import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS, MEMBER_NAV_ITEMS } from '../components/nav-items'
import { Card } from '../components/ui'

/**
 * Minimal placeholder for nav tabs whose features haven't been built yet
 * (Member Tracking: Phase 8, Notifications: Phase 7; Coach Dashboard/
 * Members: not in Phase 6's scope) — Phase 1's nav item lists already name
 * every tab as the shell's final shape, so these exist only to keep
 * navigation from breaking, not to get ahead of those phases. Defaults to
 * `role="member"` so existing call sites (Phase 5) are unaffected.
 */
export function ComingSoonPage({
  title,
  activeHref,
  role = 'member',
}: {
  title: string
  activeHref: string
  role?: 'member' | 'coach'
}) {
  const navItems = role === 'coach' ? COACH_NAV_ITEMS : MEMBER_NAV_ITEMS

  return (
    <div className="h-dvh">
      <NavShell role={role} title="Gym" navItems={navItems} activeHref={activeHref}>
        <div className="flex h-full items-center justify-center">
          <Card className="w-full max-w-sm text-center">
            <p className="text-base font-semibold text-ink">{title}</p>
            <p className="mt-1 text-sm text-ink-soft">Coming in a later phase.</p>
          </Card>
        </div>
      </NavShell>
    </div>
  )
}

import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { cn } from '../lib/cn'
import { LogoutIcon, MenuIcon, XIcon } from './ui/icons'
import { NotificationBell } from '../notifications/NotificationBell'
import { useGymBranding } from '../gym/useGymBranding'
import { CheckInTimer } from '../attendance/CheckInTimer'

export interface NavItem {
  label: string
  href: string
  icon: ReactNode
}

interface NavShellProps {
  role: 'owner' | 'coach' | 'member' | 'staff'
  title: string
  navItems: NavItem[]
  activeHref: string
  children: ReactNode
}

/**
 * Role-based app shell (roadmap Phase 1):
 * - Member: bottom tab bar below `lg:`, sidebar from `lg:` up.
 * - Owner/Coach: sidebar from `md:` up, hamburger-triggered drawer below `md:`.
 *
 * Fills its parent's height (h-full) rather than the viewport directly, so
 * the same component works both as a real full-page shell (parent set to
 * h-dvh) and inside a bounded preview frame (e.g. /dev/components).
 */
export function NavShell({ role, title, navItems, activeHref, children }: NavShellProps) {
  const { logout } = useAuth()
  const { branding } = useGymBranding()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const isMember = role === 'member'

  return (
    <div className="flex h-full w-full bg-paper">
      <aside
        className={cn(
          'hidden shrink-0 flex-col border-r border-line bg-card',
          isMember ? 'lg:flex lg:w-56' : 'md:flex md:w-56',
        )}
      >
        <div className="flex h-14 shrink-0 items-center border-b border-line px-4 font-display font-semibold tracking-wide text-ink uppercase">
          {title}
        </div>
        <nav className="flex flex-1 flex-col gap-1 overflow-y-auto p-3">
          {navItems.map((item) => (
            <SidebarLink key={item.href} item={item} active={item.href === activeHref} />
          ))}
        </nav>
        <div className="shrink-0 border-t border-line p-3">
          <LogoutButton onClick={logout} />
        </div>
      </aside>

      {!isMember && drawerOpen ? (
        <div className="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true">
          <div
            className="absolute inset-0 bg-black/40"
            aria-hidden="true"
            onClick={() => setDrawerOpen(false)}
          />
          <div className="relative flex h-full w-64 flex-col bg-card shadow-lg">
            <div className="flex h-14 shrink-0 items-center justify-between border-b border-line px-4">
              <span className="font-display font-semibold tracking-wide text-ink uppercase">{title}</span>
              <button
                type="button"
                aria-label="Close menu"
                onClick={() => setDrawerOpen(false)}
                className="flex min-h-touch min-w-touch items-center justify-center rounded-md text-ink-soft hover:bg-paper-dim focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
              >
                <XIcon />
              </button>
            </div>
            <nav className="flex flex-1 flex-col gap-1 overflow-y-auto p-3">
              {navItems.map((item) => (
                <SidebarLink
                  key={item.href}
                  item={item}
                  active={item.href === activeHref}
                  onClick={() => setDrawerOpen(false)}
                />
              ))}
            </nav>
            <div className="shrink-0 border-t border-line p-3">
              <LogoutButton
                onClick={() => {
                  setDrawerOpen(false)
                  logout()
                }}
              />
            </div>
          </div>
        </div>
      ) : null}

      {/*
        min-w-0 here is the actual fix for horizontal page overflow, not
        the one on <main> below: this div is the flex item that sits
        directly in the outer row (alongside the sidebar), so IT is what
        needs to be allowed to shrink below its content's natural width.
        Without it, any wide descendant deep inside main (e.g. a chart's
        own overflow-x-auto scroll container) inflates this wrapper and
        stretches the whole page — the inner container never gets the
        chance to scroll on its own. Roadmap Phase 1: "no horizontal
        scroll" at any viewport.
      */}
      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        {/*
          DESIGN-SYSTEM.md §4.1: the nav header is one of exactly two
          places a gym's brand color is allowed to appear — a colored
          accent border, not a repainted background (keeps `text-ink`
          contrast safe regardless of what hex an Owner picks). The
          logo, when set, sits in the header's "logo area" per the same
          rule. Nothing else in this header (menu icon, title text,
          NotificationBell) reads brandColor.
        */}
        <header
          className={cn(
            'flex h-14 shrink-0 items-center gap-3 bg-card px-4',
            branding.brandColor ? 'border-b-[3px]' : 'border-b border-line',
          )}
          style={branding.brandColor ? { borderBottomColor: branding.brandColor } : undefined}
        >
          {!isMember ? (
            <button
              type="button"
              aria-label="Open menu"
              onClick={() => setDrawerOpen(true)}
              className="flex min-h-touch min-w-touch items-center justify-center rounded-md text-ink hover:bg-paper-dim focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink md:hidden"
            >
              <MenuIcon />
            </button>
          ) : null}
          {branding.logoUrl ? (
            <img src={branding.logoUrl} alt="" className="h-8 w-8 shrink-0 rounded object-contain" />
          ) : null}
          <h1 className="font-display text-base font-semibold tracking-wide text-ink uppercase">{title}</h1>
          {isMember ? <CheckInTimer /> : null}
          <NotificationBell role={role} />
        </header>

        {/* overflow-x-hidden: a hard backstop, clipping anything that still slips past the min-w-0 fix above. */}
        <main className="min-w-0 flex-1 overflow-x-hidden overflow-y-auto p-4 sm:p-6">{children}</main>

        {isMember ? (
          <nav className="flex shrink-0 border-t border-line bg-card lg:hidden">
            {navItems.map((item) => (
              <TabLink key={item.href} item={item} active={item.href === activeHref} />
            ))}
          </nav>
        ) : null}
      </div>
    </div>
  )
}

/** Same footprint/spacing as SidebarLink, but a <button> (an action, not a route) with no active state. */
function LogoutButton({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex min-h-touch w-full items-center gap-3 rounded-md border-l-[3px] border-transparent pl-3 pr-3 text-sm font-medium text-ink-soft hover:bg-paper-dim hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
    >
      <LogoutIcon />
      Log out
    </button>
  )
}

function SidebarLink({
  item,
  active,
  onClick,
}: {
  item: NavItem
  active: boolean
  onClick?: () => void
}) {
  return (
    <Link
      to={item.href}
      onClick={onClick}
      className={cn(
        // DESIGN-SYSTEM.md §3: nav active state is a left border accent,
        // not a filled background. pl is reduced by the border width on
        // the active item so icon/label don't shift versus inactive ones.
        'flex min-h-touch items-center gap-3 border-l-[3px] pr-3 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink',
        active
          ? 'border-ink bg-paper-dim/60 pl-[9px] font-semibold text-ink'
          : 'border-transparent pl-3 text-ink-soft hover:bg-paper-dim hover:text-ink',
      )}
    >
      {item.icon}
      {item.label}
    </Link>
  )
}

function TabLink({ item, active }: { item: NavItem; active: boolean }) {
  return (
    <Link
      to={item.href}
      className={cn(
        // Same border-accent rule as the sidebar, adapted to a horizontal
        // tab bar: a top border instead of a left one.
        'flex min-h-touch min-w-touch flex-1 flex-col items-center justify-center gap-0.5 border-t-[3px] pt-[1px] text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ink',
        active ? 'border-ink text-ink' : 'border-transparent text-ink-soft',
      )}
    >
      {item.icon}
      {item.label}
    </Link>
  )
}

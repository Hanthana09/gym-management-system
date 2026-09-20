import { useEffect, useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS, MEMBER_NAV_ITEMS, OWNER_NAV_ITEMS, STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Pagination } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { cn } from '../lib/cn'
import { usePagination } from '../lib/usePagination'
import { useNotifications } from '../notifications/useNotifications'
import type { NotificationDto, SourceRole } from '../notifications/types'

const PAGE_SIZE = 20

const NAV_ITEMS = { owner: OWNER_NAV_ITEMS, coach: COACH_NAV_ITEMS, member: MEMBER_NAV_ITEMS, staff: STAFF_NAV_ITEMS }

const ACTIVE_HREF: Record<'owner' | 'coach' | 'member' | 'staff', string> = {
  owner: '/owner/notifications',
  coach: '/coach/notifications',
  member: '/member/notifications',
  staff: '/staff/notifications',
}

// DESIGN-SYSTEM.md §1 role colors, same mapping NotificationBell.tsx uses
// for its own type/source tag — kept in sync with that component rather
// than imported from it (that file doesn't export the constant).
const SOURCE_ROLE_TAG: Record<SourceRole, string> = {
  owner: 'bg-owner-soft text-owner',
  coach: 'bg-coach-soft text-coach',
  staff: 'bg-gray-100 text-gray-600',
  member: 'bg-member-soft text-member',
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

function matchesSearch(notification: NotificationDto, query: string): boolean {
  if (query === '') return true
  const haystack = [notification.title, notification.body, notification.type, notification.sourceRole ?? '']
    .join(' ')
    .toLowerCase()

  return haystack.includes(query)
}

function TypeTag({ notification }: { notification: NotificationDto }) {
  return (
    <span
      className={cn(
        'rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase',
        notification.sourceRole ? SOURCE_ROLE_TAG[notification.sourceRole] : 'bg-paper-dim text-ink-soft',
      )}
    >
      {notification.type}
    </span>
  )
}

/**
 * functional requirements §6.1: "...can view a history of past
 * notifications" — NotificationBell only ever shows a short popover list;
 * this is that full, searchable history behind a sidebar/tab menu item,
 * one shared component mounted at `/<role>/notifications` for all four
 * roles (role read from useAuth(), same shared-page pattern already used
 * by ExpensesPage/RetailSalePage for Owner+Staff).
 *
 * Reuses useNotifications() as-is: `GET /v1/notifications` is already
 * scoped to the calling user server-side
 * (CurrentUserNotificationsProvider + NotificationVoter::VIEW's own-only
 * rule), so every role automatically sees only its own notifications —
 * no new endpoint or role branching needed on the data side, only on
 * which nav items / active href the shell renders.
 */
export function NotificationsPage() {
  const { user } = useAuth()
  const role = user?.role ?? 'member'
  const { notifications, unreadCount, loaded, markRead, markAllRead } = useNotifications()
  const [search, setSearch] = useState('')

  const visibleNotifications = useMemo(() => {
    const query = search.trim().toLowerCase()

    return notifications.filter((notification) => matchesSearch(notification, query))
  }, [notifications, search])

  const { page, pageCount, paged: pagedNotifications, rangeStart, rangeEnd, total, setPage } = usePagination(
    visibleNotifications,
    PAGE_SIZE,
  )

  // Same "land back on page 1" rule as OwnerMembersPage/OwnerInvoicesPage/
  // ExpensesPage/RetailSalePage — a search or a newly-arrived notification
  // (via Mercure) can otherwise strand the view on a now-empty page.
  useEffect(() => {
    setPage(1)
  }, [search, notifications.length, setPage])

  return (
    <div className="h-dvh">
      <NavShell role={role} title="Gym" navItems={NAV_ITEMS[role]} activeHref={ACTIVE_HREF[role]}>
        <div className="mx-auto max-w-5xl">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Notifications</h1>
            <div className="flex flex-wrap items-center gap-3">
              <div className="w-full sm:w-64">
                <Input
                  label="Search"
                  placeholder="Title, body, or type"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
              {unreadCount > 0 ? (
                <Button variant="secondary" onClick={() => void markAllRead()}>
                  Mark all read
                </Button>
              ) : null}
            </div>
          </div>

          {!loaded ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
            </Card>
          ) : notifications.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No notifications yet.</p>
            </Card>
          ) : visibleNotifications.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No notifications match this search.</p>
            </Card>
          ) : null}

          {/* Card list — default (mobile/tablet), same card/table split as OwnerMembersPage/OwnerInvoicesPage/ExpensesPage/RetailSalePage */}
          <ul className="flex flex-col gap-3 lg:hidden">
            {pagedNotifications.map((notification) => (
              <li key={notification.id}>
                <Card className={cn(!notification.read && 'bg-paper-dim/60')}>
                  <div className="flex items-start justify-between gap-2">
                    <TypeTag notification={notification} />
                    {!notification.read ? (
                      <button
                        type="button"
                        onClick={() => void markRead(notification.id)}
                        className="text-xs font-medium text-ink-soft underline hover:text-ink"
                      >
                        Mark read
                      </button>
                    ) : null}
                  </div>
                  <p className="mt-1.5 text-sm font-medium text-ink">{notification.title}</p>
                  <p className="text-sm text-ink-soft">{notification.body}</p>
                  <p className="mt-1 font-mono text-xs text-ink-soft">{formatDateTime(notification.createdAt)}</p>
                </Card>
              </li>
            ))}
          </ul>

          {/* Table — lg: and up */}
          {pagedNotifications.length > 0 ? (
            <table className="hidden w-full table-fixed border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
              <thead>
                <tr className="text-left text-sm text-ink-soft">
                  <th className="w-[15%] border-b border-line px-4 py-3">Date</th>
                  <th className="w-[12%] border-b border-line px-4 py-3">Type</th>
                  <th className="w-[20%] border-b border-line px-4 py-3">Title</th>
                  <th className="w-[35%] border-b border-line px-4 py-3">Details</th>
                  <th className="w-[9%] border-b border-line px-4 py-3">Status</th>
                  <th className="w-[9%] border-b border-line px-4 py-3">Action</th>
                </tr>
              </thead>
              <tbody>
                {pagedNotifications.map((notification) => (
                  <tr key={notification.id} className={cn('text-sm text-ink', !notification.read && 'bg-paper-dim/60')}>
                    <td className="border-b border-line/60 px-4 py-3 whitespace-nowrap text-ink-soft">
                      {formatDateTime(notification.createdAt)}
                    </td>
                    <td className="border-b border-line/60 px-4 py-3">
                      <TypeTag notification={notification} />
                    </td>
                    <td className="border-b border-line/60 px-4 py-3 font-medium break-words">{notification.title}</td>
                    <td className="border-b border-line/60 px-4 py-3 break-words text-ink-soft">{notification.body}</td>
                    <td className="border-b border-line/60 px-4 py-3">
                      {notification.read ? (
                        <span className="text-xs text-ink-soft">Read</span>
                      ) : (
                        <span className="text-xs font-semibold text-ink">Unread</span>
                      )}
                    </td>
                    <td className="border-b border-line/60 px-4 py-3">
                      {!notification.read ? (
                        <button
                          type="button"
                          onClick={() => void markRead(notification.id)}
                          className="text-xs font-medium text-ink-soft underline hover:text-ink"
                        >
                          Mark read
                        </button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}

          <div className="mt-4">
            <Pagination
              page={page}
              pageCount={pageCount}
              rangeStart={rangeStart}
              rangeEnd={rangeEnd}
              total={total}
              onChange={setPage}
            />
          </div>
        </div>
      </NavShell>
    </div>
  )
}

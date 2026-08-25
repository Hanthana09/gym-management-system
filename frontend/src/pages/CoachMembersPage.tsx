import { useEffect, useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS } from '../components/nav-items'
import { Card, Input, Pagination } from '../components/ui'
import { usePagination } from '../lib/usePagination'
import { useMembers } from '../members/useMembers'
import type { MemberAccountStatus, MemberListItemDto } from '../members/types'

const PAGE_SIZE = 20

// Same status vocabulary as OwnerMembersPage/StaffDashboardPage's Pill.
const ACCOUNT_STATUS_STYLES: Record<MemberAccountStatus, string> = {
  active: 'bg-green-100 text-green-800',
  pending_approval: 'bg-amber-100 text-amber-800',
  suspended: 'bg-red-100 text-red-800',
}

const MEMBERSHIP_STATUS_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  paused: 'bg-amber-100 text-amber-800',
  expired: 'bg-gray-100 text-gray-600',
  cancelled: 'bg-red-100 text-red-800',
}

function Pill({ label, styles }: { label: string; styles: string }) {
  return <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
}

function matchesSearch(member: MemberListItemDto, query: string): boolean {
  if (query === '') return true
  const haystack = [member.name, member.email, member.phone, member.memberId].filter(Boolean).join(' ').toLowerCase()

  return haystack.includes(query)
}

/**
 * Completes the Coach nav's "Members" item, previously a ComingSoonPage
 * stub. Backend's `GET /members` now also accepts a Coach caller
 * (MemberController::list(), MemberVoter::VIEW's "Coach: own clients"
 * branch — architecture doc §9.1 always declared this; MemberProfile::
 * hasCoach() just had no real implementation to reach it with until PT
 * sessions existed to derive it from) and, for a Coach specifically,
 * scopes the response to members who've had a PT session with them —
 * no coach directory alongside, unlike the Owner/Staff roster. Read-only
 * by design: suspend/reactivate/check-in all stay behind MemberVoter::
 * MANAGE and AttendanceVoter::CHECK_IN, neither of which has a Coach
 * branch, so no such controls appear here — this is a client list, not a
 * management console. Workout/tracking data intentionally isn't shown
 * here either (architecture doc §9's still-open decision on whether
 * Coaches should see WORKOUT_LOG/BODY_METRIC — out of scope until that's
 * resolved, see PersonalTrackingVoter's own docblock).
 */
export function CoachMembersPage() {
  const { members, loaded } = useMembers()
  const [search, setSearch] = useState('')

  const visibleMembers = useMemo(() => {
    const query = search.trim().toLowerCase()

    return members.filter((member) => matchesSearch(member, query))
  }, [members, search])

  const { page, pageCount, paged: pagedMembers, rangeStart, rangeEnd, total, setPage } = usePagination(visibleMembers, PAGE_SIZE)

  // A new search query can shrink the result set or reorder it entirely —
  // always land back on page 1 rather than risk stranding on a
  // now-empty or now-mismatched page.
  useEffect(() => {
    setPage(1)
  }, [search, setPage])

  return (
    <div className="h-dvh">
      <NavShell role="coach" title="Gym" navItems={COACH_NAV_ITEMS} activeHref="/coach/members">
        <div className="mx-auto flex max-w-4xl flex-col gap-4">
          <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">My members</h1>

          <Input label="Search" placeholder="Search by name, email, phone, or member ID" value={search} onChange={(e) => setSearch(e.target.value)} />

          {!loaded ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
            </Card>
          ) : visibleMembers.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">
                {members.length === 0 ? "No members yet — they'll show up here once you've had a session together." : 'No one matches this search.'}
              </p>
            </Card>
          ) : (
            <>
              <div className="flex flex-col gap-3">
                {pagedMembers.map((member) => (
                  <Card key={member.id}>
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-ink">{member.name}</p>
                        {member.email ? <p className="truncate text-sm text-ink-soft">{member.email}</p> : null}
                        {member.phone ? <p className="truncate text-sm text-ink-soft">{member.phone}</p> : null}
                        {member.memberId ? <p className="mt-0.5 font-mono text-xs text-ink-soft">{member.memberId}</p> : null}
                      </div>
                      <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
                    </div>

                    {member.membership ? (
                      <div className="mt-3 flex items-center gap-2">
                        <span className="text-sm text-ink-soft">{member.membership.planName}</span>
                        <Pill
                          label={member.membership.status}
                          styles={MEMBERSHIP_STATUS_STYLES[member.membership.status] ?? 'bg-gray-100 text-gray-600'}
                        />
                      </div>
                    ) : (
                      <p className="mt-3 text-sm text-ink-soft">No plan</p>
                    )}
                  </Card>
                ))}
              </div>

              <Pagination page={page} pageCount={pageCount} rangeStart={rangeStart} rangeEnd={rangeEnd} total={total} onChange={setPage} />
            </>
          )}
        </div>
      </NavShell>
    </div>
  )
}

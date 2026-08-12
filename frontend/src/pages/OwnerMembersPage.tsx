import { useEffect, useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Select } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useMembers } from '../members/useMembers'
import { useOwnerPlans } from '../membership/useOwnerPlans'
import { useEnrollMember } from '../membership/useEnrollMember'
import type { MemberAccountStatus, MemberListItemDto, RosterRole } from '../members/types'

// DESIGN-SYSTEM.md §3 "Tag/pill" typographic pattern — same approach as
// MyMembershipCard's membership-status pill, one palette per concept.
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

// Same role palette as the Badge component (DESIGN-SYSTEM.md §4) — one
// color per role everywhere it shows up, not a one-off scheme.
const ROLE_STYLES: Record<RosterRole, string> = {
  member: 'bg-member-soft text-member',
  coach: 'bg-coach-soft text-coach',
}

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

type SortField = 'name' | 'role' | 'status' | 'plan' | 'joined'
type SortDirection = 'asc' | 'desc'
type RoleFilter = 'all' | RosterRole

const SORT_OPTIONS: { value: SortField; label: string }[] = [
  { value: 'name', label: 'Name' },
  { value: 'role', label: 'Role' },
  { value: 'status', label: 'Status' },
  { value: 'plan', label: 'Plan' },
  { value: 'joined', label: 'Joined' },
]

const ROLE_FILTER_OPTIONS: { value: RoleFilter; label: string }[] = [
  { value: 'all', label: 'All roles' },
  { value: 'member', label: 'Members' },
  { value: 'coach', label: 'Coaches' },
]

const PAGE_SIZE = 20

function matchesSearch(member: MemberListItemDto, query: string): boolean {
  if (query === '') return true
  const haystack = [member.name, member.email, member.phone].filter(Boolean).join(' ').toLowerCase()

  return haystack.includes(query)
}

function compareBy(field: SortField, a: MemberListItemDto, b: MemberListItemDto): number {
  switch (field) {
    case 'name':
      return a.name.localeCompare(b.name)
    case 'role':
      return a.role.localeCompare(b.role)
    case 'status':
      return a.status.localeCompare(b.status)
    case 'plan':
      return (a.membership?.planName ?? '').localeCompare(b.membership?.planName ?? '')
    case 'joined':
      return a.joinedAt.localeCompare(b.joinedAt)
  }
}

/**
 * Owner's roster — members and coaches together, tagged with a `role`
 * column, so there's one place to see everyone at the gym. Card grid on
 * mobile, sortable table at lg: — per CLAUDE.md's explicit rule, NOT a
 * horizontally-scrolled shrunk table. Search/sort/role-filter/pagination
 * are shared state driving both layouts so the two stay in sync as the
 * viewport crosses the lg: breakpoint.
 */
export function OwnerMembersPage() {
  const { members, loaded, refresh } = useMembers()
  const { plans, loaded: plansLoaded } = useOwnerPlans()
  const { enroll } = useEnrollMember()
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState<RoleFilter>('all')
  const [sortField, setSortField] = useState<SortField>('name')
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc')
  const [page, setPage] = useState(1)
  const [enrollingMember, setEnrollingMember] = useState<MemberListItemDto | null>(null)

  const visibleMembers = useMemo(() => {
    const query = search.trim().toLowerCase()
    const direction = sortDirection === 'asc' ? 1 : -1

    return members
      .filter((member) => roleFilter === 'all' || member.role === roleFilter)
      .filter((member) => matchesSearch(member, query))
      .sort((a, b) => direction * compareBy(sortField, a, b))
  }, [members, search, roleFilter, sortField, sortDirection])

  // Any change to what's being shown or how it's ordered can move a
  // given row to a different page (or shrink the result set entirely) —
  // always land back on page 1 rather than risk stranding the user on a
  // now-empty page.
  useEffect(() => {
    setPage(1)
  }, [search, roleFilter, sortField, sortDirection])

  const pageCount = Math.max(1, Math.ceil(visibleMembers.length / PAGE_SIZE))
  const currentPage = Math.min(page, pageCount)
  const pagedMembers = visibleMembers.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)
  const rangeStart = visibleMembers.length === 0 ? 0 : (currentPage - 1) * PAGE_SIZE + 1
  const rangeEnd = Math.min(currentPage * PAGE_SIZE, visibleMembers.length)

  function toggleSort(field: SortField) {
    if (field === sortField) {
      setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortField(field)
      setSortDirection('asc')
    }
  }

  const sortIndicator = sortDirection === 'asc' ? '↑' : '↓'

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/members">
        <div className="mx-auto flex max-w-4xl flex-col gap-4">
          <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Members</h1>

          <div className="flex flex-col gap-3 sm:flex-row">
            <div className="flex-1">
              <Input
                label="Search"
                placeholder="Search by name, email, or phone"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="sm:w-40">
              <Select
                label="Role"
                value={roleFilter}
                onChange={(e) => setRoleFilter(e.target.value as RoleFilter)}
                options={ROLE_FILTER_OPTIONS}
              />
            </div>
            {/* Sort control for the card view — the table's own column
                headers take over this job at lg: and up. */}
            <div className="flex gap-2 lg:hidden">
              <div className="flex-1">
                <Select
                  label="Sort by"
                  value={sortField}
                  onChange={(e) => setSortField(e.target.value as SortField)}
                  options={SORT_OPTIONS}
                />
              </div>
              <button
                type="button"
                aria-label={sortDirection === 'asc' ? 'Sort ascending' : 'Sort descending'}
                onClick={() => setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'))}
                className="mt-[1.625rem] min-h-touch min-w-touch rounded-md border border-line bg-card px-3 text-base text-ink"
              >
                {sortIndicator}
              </button>
            </div>
          </div>

          {loaded && members.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No members yet.</p>
            </Card>
          ) : null}

          {loaded && members.length > 0 && visibleMembers.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No one matches these filters.</p>
            </Card>
          ) : null}

          {/* Card list — default (mobile/tablet) */}
          <div className="flex flex-col gap-3 lg:hidden">
            {pagedMembers.map((member) => (
              <Card key={member.id}>
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <p className="text-sm font-semibold text-ink">{member.name}</p>
                      <Pill label={member.role} styles={ROLE_STYLES[member.role]} />
                    </div>
                    <p className="mt-0.5 text-sm text-ink-soft">{member.email ?? member.phone}</p>
                  </div>
                  <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
                </div>
                <div className="mt-3 flex items-center justify-between text-sm">
                  {member.membership ? (
                    <div className="flex items-center gap-2">
                      <span className="text-ink-soft">{member.membership.planName}</span>
                      <Pill
                        label={member.membership.status}
                        styles={MEMBERSHIP_STATUS_STYLES[member.membership.status] ?? 'bg-gray-100 text-gray-600'}
                      />
                    </div>
                  ) : member.role === 'coach' ? (
                    <span className="text-ink-soft">—</span>
                  ) : (
                    <Button variant="secondary" onClick={() => setEnrollingMember(member)}>
                      Enroll in plan
                    </Button>
                  )}
                  <span className="font-mono text-xs text-ink-soft">
                    {new Date(member.joinedAt).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })}
                  </span>
                </div>
              </Card>
            ))}
          </div>

          {/* Table — lg: and up, with sortable column headers */}
          {pagedMembers.length > 0 ? (
            <table className="hidden w-full border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
              <thead>
                <tr className="text-left text-sm text-ink-soft">
                  <SortableHeader field="name" label="Name" active={sortField === 'name'} indicator={sortIndicator} onClick={toggleSort} />
                  <th className="border-b border-line px-4 py-3">Contact</th>
                  <SortableHeader field="role" label="Role" active={sortField === 'role'} indicator={sortIndicator} onClick={toggleSort} />
                  <SortableHeader field="status" label="Status" active={sortField === 'status'} indicator={sortIndicator} onClick={toggleSort} />
                  <SortableHeader field="plan" label="Plan" active={sortField === 'plan'} indicator={sortIndicator} onClick={toggleSort} />
                  <SortableHeader field="joined" label="Joined" active={sortField === 'joined'} indicator={sortIndicator} onClick={toggleSort} />
                </tr>
              </thead>
              <tbody>
                {pagedMembers.map((member) => (
                  <tr key={member.id} className="text-sm text-ink">
                    <td className="border-b border-line/60 px-4 py-3 font-medium">{member.name}</td>
                    <td className="border-b border-line/60 px-4 py-3 text-ink-soft">{member.email ?? member.phone}</td>
                    <td className="border-b border-line/60 px-4 py-3">
                      <Pill label={member.role} styles={ROLE_STYLES[member.role]} />
                    </td>
                    <td className="border-b border-line/60 px-4 py-3">
                      <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
                    </td>
                    <td className="border-b border-line/60 px-4 py-3">
                      {member.membership ? (
                        <div className="flex items-center gap-2">
                          <span>{member.membership.planName}</span>
                          <Pill
                            label={member.membership.status}
                            styles={MEMBERSHIP_STATUS_STYLES[member.membership.status] ?? 'bg-gray-100 text-gray-600'}
                          />
                        </div>
                      ) : member.role === 'coach' ? (
                        <span className="text-ink-soft">—</span>
                      ) : (
                        <Button variant="secondary" onClick={() => setEnrollingMember(member)}>
                          Enroll in plan
                        </Button>
                      )}
                    </td>
                    <td className="border-b border-line/60 px-4 py-3 font-mono text-xs text-ink-soft">
                      {new Date(member.joinedAt).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}

          {visibleMembers.length > 0 ? (
            <div className="flex items-center justify-between gap-3">
              <p className="text-sm text-ink-soft">
                {rangeStart}–{rangeEnd} of {visibleMembers.length}
              </p>
              <div className="flex items-center gap-2">
                <Button
                  variant="secondary"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage <= 1}
                >
                  Prev
                </Button>
                <p className="text-sm text-ink-soft">
                  Page {currentPage} of {pageCount}
                </p>
                <Button
                  variant="secondary"
                  onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                  disabled={currentPage >= pageCount}
                >
                  Next
                </Button>
              </div>
            </div>
          ) : null}
        </div>

        <EnrollModal
          member={enrollingMember}
          plans={plans}
          plansLoaded={plansLoaded}
          onClose={() => setEnrollingMember(null)}
          onEnroll={enroll}
          onEnrolled={refresh}
        />
      </NavShell>
    </div>
  )
}

interface EnrollModalProps {
  member: MemberListItemDto | null
  plans: { id: string; name: string; price: string; durationDays: number }[]
  plansLoaded: boolean
  onClose: () => void
  onEnroll: (memberUserId: string, planId: string) => Promise<unknown>
  onEnrolled: () => Promise<void>
}

/**
 * "Enroll in plan" for a member with no membership yet (e.g. bulk-
 * imported or seeded accounts) — plan picker + one confirm tap, then
 * refreshes the roster so the new plan/invoice show up immediately.
 * Enrolling auto-creates a pending Invoice (roadmap Phase 10).
 */
function EnrollModal({ member, plans, plansLoaded, onClose, onEnroll, onEnrolled }: EnrollModalProps) {
  const [planId, setPlanId] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const selectedPlanId = planId || plans[0]?.id || ''

  function handleClose() {
    setPlanId('')
    setError(null)
    setSubmitting(false)
    onClose()
  }

  async function handleSubmit() {
    if (!member || !selectedPlanId) return
    setSubmitting(true)
    setError(null)
    try {
      await onEnroll(member.id, selectedPlanId)
      await onEnrolled()
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Modal open={member !== null} onClose={handleClose} title="Enroll in plan">
      {member ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink-soft">{member.name}</p>

          {!plansLoaded ? (
            <p className="text-sm text-ink-soft">Loading plans…</p>
          ) : plans.length === 0 ? (
            <p className="text-sm text-ink-soft">No plans yet — create one first.</p>
          ) : (
            <>
              <Select
                label="Plan"
                value={selectedPlanId}
                onChange={(e) => setPlanId(e.target.value)}
                options={plans.map((plan) => ({ value: plan.id, label: `${plan.name} — $${plan.price} / ${plan.durationDays} days` }))}
              />
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              <Button fullWidth onClick={handleSubmit} disabled={submitting}>
                {submitting ? 'Enrolling…' : 'Enroll'}
              </Button>
            </>
          )}
        </div>
      ) : null}
    </Modal>
  )
}

interface SortableHeaderProps {
  field: SortField
  label: string
  active: boolean
  indicator: string
  onClick: (field: SortField) => void
}

function SortableHeader({ field, label, active, indicator, onClick }: SortableHeaderProps) {
  return (
    <th className="border-b border-line px-4 py-3">
      <button type="button" onClick={() => onClick(field)} className="flex items-center gap-1 hover:text-ink">
        {label}
        {active ? <span aria-hidden="true">{indicator}</span> : null}
      </button>
    </th>
  )
}

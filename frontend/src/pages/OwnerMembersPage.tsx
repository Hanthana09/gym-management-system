import { useEffect, useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Pagination, Select } from '../components/ui'
import { CheckInIcon } from '../components/ui/icons'
import { ApiError } from '../lib/apiClient'
import { useAuth } from '../auth/AuthContext'
import { useMembers } from '../members/useMembers'
import { useOwnerPlans } from '../membership/useOwnerPlans'
import { useEnrollMember } from '../membership/useEnrollMember'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'
import { usePagination } from '../lib/usePagination'
import type { MemberAccountStatus, MemberListItemDto, RosterRole } from '../members/types'

const BLOCKED_REASON_LABELS: Record<string, string> = {
  membership_expired: 'Membership expired',
  membership_paused: 'Membership paused',
  membership_cancelled: 'Membership cancelled',
  account_suspended: 'Account suspended',
  no_membership: 'No membership',
}

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
  const { authFetch } = useAuth()
  const { members, loaded, refresh, updateStatus } = useMembers()
  const { plans, loaded: plansLoaded } = useOwnerPlans()
  const { enroll } = useEnrollMember()
  const { branches } = useBranches()
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState<RoleFilter>('all')
  const [sortField, setSortField] = useState<SortField>('name')
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc')
  const [enrollingMember, setEnrollingMember] = useState<MemberListItemDto | null>(null)
  const [confirmingSuspendId, setConfirmingSuspendId] = useState<string | null>(null)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [statusUpdatingId, setStatusUpdatingId] = useState<string | null>(null)
  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)
  const [checkInState, setCheckInState] = useState<Record<string, { status: 'checking' | 'success' | 'blocked' | 'error'; message: string }>>({})

  // One switcher, two jobs: filters the roster below (null = every
  // branch, same as the Owner dashboard's reports switcher — a Member
  // isn't branch-restricted, this is purely a "who's at branch X" view),
  // and doubles as "which branch am I working from" for front-desk
  // check-in, which always needs one concrete branch — falls back to the
  // primary branch while the filter is on "All branches".
  const activeBranchId = selectedBranchId ?? defaultBranchId(branches)

  async function handleCheckIn(member: MemberListItemDto) {
    setCheckInState((prev) => ({ ...prev, [member.id]: { status: 'checking', message: '' } }))
    try {
      const result = await authFetch<{ checkInAt: string }>(`/members/${member.id}/checkin`, {
        method: 'POST',
        body: { branchId: activeBranchId },
      })
      const time = new Date(result.checkInAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
      setCheckInState((prev) => ({ ...prev, [member.id]: { status: 'success', message: `Checked in at ${time}` } }))
    } catch (err) {
      if (err instanceof ApiError && err.code === 'checkin_blocked' && err.reason) {
        setCheckInState((prev) => ({
          ...prev,
          [member.id]: { status: 'blocked', message: BLOCKED_REASON_LABELS[err.reason as string] ?? 'Check-in blocked' },
        }))
      } else {
        setCheckInState((prev) => ({ ...prev, [member.id]: { status: 'error', message: "Couldn't check in." } }))
      }
    }
  }

  async function handleReactivate(id: string) {
    setStatusError(null)
    setStatusUpdatingId(id)
    try {
      await updateStatus(id, 'active')
    } catch (err) {
      setStatusError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setStatusUpdatingId(null)
    }
  }

  async function handleConfirmSuspend(id: string) {
    setStatusError(null)
    setStatusUpdatingId(id)
    try {
      await updateStatus(id, 'suspended')
      setConfirmingSuspendId(null)
    } catch (err) {
      setStatusError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setStatusUpdatingId(null)
    }
  }

  const visibleMembers = useMemo(() => {
    const query = search.trim().toLowerCase()
    const direction = sortDirection === 'asc' ? 1 : -1

    return members
      .filter((member) => roleFilter === 'all' || member.role === roleFilter)
      .filter((member) => selectedBranchId === null || member.branchIds.includes(selectedBranchId))
      .filter((member) => matchesSearch(member, query))
      .sort((a, b) => direction * compareBy(sortField, a, b))
  }, [members, search, roleFilter, selectedBranchId, sortField, sortDirection])

  const { page: currentPage, pageCount, paged: pagedMembers, rangeStart, rangeEnd, total, setPage } = usePagination(
    visibleMembers,
    PAGE_SIZE,
  )

  // Any change to what's being shown or how it's ordered can move a
  // given row to a different page (or shrink the result set entirely) —
  // always land back on page 1 rather than risk stranding the user on a
  // now-empty page.
  useEffect(() => {
    setPage(1)
  }, [search, roleFilter, selectedBranchId, sortField, sortDirection, setPage])

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
          <div className="flex items-center gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Members</h1>
            {/* Filters the roster below and picks the front-desk check-in branch — absent for single-branch gyms (DESIGN-SYSTEM.md §4.2). */}
            <BranchSwitcher branches={branches} value={selectedBranchId} onChange={setSelectedBranchId} allowAll />
          </div>

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

          {statusError ? <p className="text-sm text-red-600">{statusError}</p> : null}

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
                {member.role === 'member' ? (
                  <div className="mt-3 flex flex-col gap-2 border-t border-line pt-3">
                    <div className="flex items-center justify-between gap-2">
                      <MemberStatusAction
                        member={member}
                        confirming={confirmingSuspendId === member.id}
                        updating={statusUpdatingId === member.id}
                        onRequestSuspend={() => {
                          setStatusError(null)
                          setConfirmingSuspendId(member.id)
                        }}
                        onCancelSuspend={() => setConfirmingSuspendId(null)}
                        onConfirmSuspend={() => handleConfirmSuspend(member.id)}
                        onReactivate={() => handleReactivate(member.id)}
                      />
                      <Button
                        variant="secondary"
                        onClick={() => handleCheckIn(member)}
                        disabled={checkInState[member.id]?.status === 'checking'}
                      >
                        <CheckInIcon />
                        {checkInState[member.id]?.status === 'checking' ? 'Checking in…' : 'Check in'}
                      </Button>
                    </div>
                    {checkInState[member.id] && checkInState[member.id].status !== 'checking' ? (
                      <p
                        className={`text-sm ${
                          checkInState[member.id].status === 'success'
                            ? 'text-green-700'
                            : checkInState[member.id].status === 'blocked'
                              ? 'text-red-700'
                              : 'text-ink-soft'
                        }`}
                      >
                        {checkInState[member.id].message}
                      </p>
                    ) : null}
                  </div>
                ) : null}
              </Card>
            ))}
          </div>

          {/*
            Table — lg: and up, with sortable column headers. table-fixed +
            an explicit width per column (summing to 100%) is the actual
            fix here: with the default table-layout: auto, a long
            unbroken name or email had no break opportunity and forced
            its column — and the whole table — wider than the page
            (CLAUDE.md: no horizontal page overflow at any viewport).
            break-words on every cell lets that same long text wrap
            within its now-fixed column instead.
          */}
          {pagedMembers.length > 0 ? (
            <table className="hidden w-full table-fixed border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
              <thead>
                <tr className="text-left text-sm text-ink-soft">
                  <SortableHeader field="name" label="Name" active={sortField === 'name'} indicator={sortIndicator} onClick={toggleSort} className="w-[14%]" />
                  <th className="w-[16%] border-b border-line px-4 py-3">Contact</th>
                  <SortableHeader field="role" label="Role" active={sortField === 'role'} indicator={sortIndicator} onClick={toggleSort} className="w-[7%]" />
                  <SortableHeader field="status" label="Status" active={sortField === 'status'} indicator={sortIndicator} onClick={toggleSort} className="w-[8%]" />
                  <SortableHeader field="plan" label="Plan" active={sortField === 'plan'} indicator={sortIndicator} onClick={toggleSort} className="w-[14%]" />
                  <SortableHeader field="joined" label="Joined" active={sortField === 'joined'} indicator={sortIndicator} onClick={toggleSort} className="w-[9%]" />
                  {/* Widest column on purpose — this is the only one holding two buttons (or, mid-confirm, "Suspend {name}?" text plus two more), not just a badge or a date. */}
                  <th className="w-[32%] border-b border-line px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {pagedMembers.map((member) => (
                  <tr key={member.id} className="text-sm text-ink">
                    <td className="border-b border-line/60 px-4 py-3 font-medium break-words">{member.name}</td>
                    <td className="border-b border-line/60 px-4 py-3 text-ink-soft break-words">{member.email ?? member.phone}</td>
                    <td className="border-b border-line/60 px-4 py-3">
                      <Pill label={member.role} styles={ROLE_STYLES[member.role]} />
                    </td>
                    <td className="border-b border-line/60 px-4 py-3">
                      <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
                    </td>
                    <td className="border-b border-line/60 px-4 py-3 break-words">
                      {member.membership ? (
                        <div className="flex flex-wrap items-center gap-2">
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
                    <td className="border-b border-line/60 px-4 py-3">
                      {member.role === 'member' ? (
                        <div className="flex flex-col gap-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <MemberStatusAction
                              member={member}
                              confirming={confirmingSuspendId === member.id}
                              updating={statusUpdatingId === member.id}
                              onRequestSuspend={() => {
                                setStatusError(null)
                                setConfirmingSuspendId(member.id)
                              }}
                              onCancelSuspend={() => setConfirmingSuspendId(null)}
                              onConfirmSuspend={() => handleConfirmSuspend(member.id)}
                              onReactivate={() => handleReactivate(member.id)}
                              compact
                            />
                            <Button
                              variant="secondary"
                              onClick={() => handleCheckIn(member)}
                              disabled={checkInState[member.id]?.status === 'checking'}
                            >
                              <CheckInIcon />
                              {checkInState[member.id]?.status === 'checking' ? 'Checking in…' : 'Check in'}
                            </Button>
                          </div>
                          {checkInState[member.id] && checkInState[member.id].status !== 'checking' ? (
                            <p
                              className={`text-sm ${
                                checkInState[member.id].status === 'success'
                                  ? 'text-green-700'
                                  : checkInState[member.id].status === 'blocked'
                                    ? 'text-red-700'
                                    : 'text-ink-soft'
                              }`}
                            >
                              {checkInState[member.id].message}
                            </p>
                          ) : null}
                        </div>
                      ) : (
                        <span className="text-ink-soft">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}

          <Pagination
            page={currentPage}
            pageCount={pageCount}
            rangeStart={rangeStart}
            rangeEnd={rangeEnd}
            total={total}
            onChange={setPage}
          />
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

interface MemberStatusActionProps {
  member: MemberListItemDto
  confirming: boolean
  updating: boolean
  onRequestSuspend: () => void
  onCancelSuspend: () => void
  onConfirmSuspend: () => void
  onReactivate: () => void
  compact?: boolean
}

/**
 * architecture doc §7: PATCH /members/:id/status. Suspending removes
 * access (blocks check-in — Phase 5's AttendanceService), so it gets the
 * same inline confirm step as OwnerPlansPage's delete action; reactivating
 * only restores access, so it's a single tap, same asymmetry as
 * MyMembershipCard's pause/resume.
 */
function MemberStatusAction({
  member,
  confirming,
  updating,
  onRequestSuspend,
  onCancelSuspend,
  onConfirmSuspend,
  onReactivate,
  compact,
}: MemberStatusActionProps) {
  if (member.status === 'suspended') {
    return (
      <Button variant="secondary" fullWidth={!compact} onClick={onReactivate} disabled={updating}>
        {updating ? 'Reactivating…' : 'Reactivate'}
      </Button>
    )
  }

  if (confirming) {
    return (
      <div className={compact ? 'flex flex-col gap-2' : ''}>
        <p className="text-sm text-ink break-words">Suspend {member.name}?</p>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" fullWidth={!compact} onClick={onCancelSuspend} disabled={updating}>
            Cancel
          </Button>
          <Button variant="danger" fullWidth={!compact} onClick={onConfirmSuspend} disabled={updating}>
            {updating ? 'Suspending…' : 'Suspend'}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <Button variant="danger" fullWidth={!compact} onClick={onRequestSuspend}>
      Suspend
    </Button>
  )
}

interface SortableHeaderProps {
  field: SortField
  label: string
  active: boolean
  indicator: string
  onClick: (field: SortField) => void
  className?: string
}

function SortableHeader({ field, label, active, indicator, onClick, className }: SortableHeaderProps) {
  return (
    <th className={`border-b border-line px-4 py-3 ${className ?? ''}`}>
      <button type="button" onClick={() => onClick(field)} className="flex items-center gap-1 hover:text-ink">
        {label}
        {active ? <span aria-hidden="true">{indicator}</span> : null}
      </button>
    </th>
  )
}

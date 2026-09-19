import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Pagination, Select } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { usePagination } from '../lib/usePagination'
import { useOwnerBranches } from '../branches/useOwnerBranches'
import { useMembers } from '../members/useMembers'
import type { BranchAssignmentDto, BranchDto } from '../branches/types'
import type { MemberAccountStatus, MemberListItemDto } from '../members/types'

// Roadmap Phase 16.1 request: paginate both tables at a fixed page size
// rather than showing every row — 10 is the floor `usePagination` callers
// use elsewhere for a single-entity's sub-lists (vs. 20 for gym-wide
// rosters like OwnerMembersPage/OwnerBranchesPage).
const PAGE_SIZE = 10

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

// Same palette as OwnerMembersPage — one meaning per color everywhere it appears, not a one-off scheme (DESIGN-SYSTEM.md §3).
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

/**
 * roadmap Phase 16.1: Owner's Branch Detail screen — reached from a row on
 * OwnerBranchesPage's table/card list. Profile view + edit, Coach/Staff
 * assignment, activate/deactivate, and delete all live here now (moved off
 * the list page, same split as CoachDetailPage/MemberDetailPage). Reuses
 * useOwnerBranches()'s already-fetched list (assignments included) rather
 * than adding a dedicated GET /branches/:id — the list endpoint already
 * carries everything this screen needs.
 */
export function BranchDetailPage() {
  const { id } = useParams<{ id: string }>()
  const branchId = id ?? ''
  const navigate = useNavigate()
  const { branches, assignableUsers, loaded, updateBranch, assign, unassign, assignMember, unassignMember, deleteBranch } =
    useOwnerBranches()
  const branch = branches.find((b) => b.id === branchId) ?? null
  // Roster is gym-wide (GET /members) — filtered client-side to this
  // branch, same approach OwnerMembersPage's BranchSwitcher already uses.
  // A Member's branchIds has 0-1 entries (their Owner-assigned home
  // branch, falling back to their enrolling branch when unassigned).
  // Coaches are excluded here since they're already shown in "Assigned
  // Coach / Staff" above.
  const { members: allMembers, loaded: membersLoaded } = useMembers()
  const branchMembers = useMemo(
    () => allMembers.filter((m) => m.role === 'member' && m.branchIds.includes(branchId)),
    [allMembers, branchId],
  )
  // Candidates for the "Assign" picker below — any active Member not
  // already shown in this branch's table. Picking one who's currently
  // assigned elsewhere moves them (assignMember overwrites, it doesn't
  // error like the Coach/Staff duplicate-assignment 409 does).
  const assignableMembers = useMemo(
    () =>
      allMembers.filter(
        (m) => m.role === 'member' && m.status === 'active' && !branchMembers.some((bm) => bm.id === m.id),
      ),
    [allMembers, branchMembers],
  )
  const assignments = useMemo(() => branch?.assignments ?? [], [branch])

  const [assignmentSearch, setAssignmentSearch] = useState('')
  const [memberSearch, setMemberSearch] = useState('')

  const visibleAssignments = useMemo(() => {
    const query = assignmentSearch.trim().toLowerCase()
    if (query === '') return assignments

    return assignments.filter((a) => [a.name, a.role].join(' ').toLowerCase().includes(query))
  }, [assignments, assignmentSearch])

  const visibleMembers = useMemo(() => {
    const query = memberSearch.trim().toLowerCase()
    if (query === '') return branchMembers

    return branchMembers.filter((m) =>
      [m.name, m.email, m.phone, m.memberId].filter(Boolean).join(' ').toLowerCase().includes(query),
    )
  }, [branchMembers, memberSearch])

  const {
    page: assignmentsPage,
    pageCount: assignmentsPageCount,
    paged: pagedAssignments,
    rangeStart: assignmentsRangeStart,
    rangeEnd: assignmentsRangeEnd,
    total: assignmentsTotal,
    setPage: setAssignmentsPage,
  } = usePagination(visibleAssignments, PAGE_SIZE)

  const {
    page: membersPage,
    pageCount: membersPageCount,
    paged: pagedMembers,
    rangeStart: membersRangeStart,
    rangeEnd: membersRangeEnd,
    total: membersTotal,
    setPage: setMembersPage,
  } = usePagination(visibleMembers, PAGE_SIZE)

  // Switching branch, or a new search query reshaping either result set,
  // can strand the Owner on a now-empty or now-mismatched page — always
  // land back on page 1.
  useEffect(() => {
    setAssignmentsPage(1)
  }, [branchId, assignmentSearch, setAssignmentsPage])

  useEffect(() => {
    setMembersPage(1)
  }, [branchId, memberSearch, setMembersPage])

  const [editing, setEditing] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [assigningMember, setAssigningMember] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [statusUpdating, setStatusUpdating] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState(false)

  async function handleToggleStatus() {
    if (!branch) return
    setStatusError(null)
    setStatusUpdating(true)
    try {
      await updateBranch(branch.id, { status: branch.status === 'active' ? 'inactive' : 'active' })
    } catch (err) {
      setStatusError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setStatusUpdating(false)
    }
  }

  async function handleDelete() {
    if (!branch) return
    setDeleteError(null)
    setDeleting(true)
    try {
      await deleteBranch(branch.id)
      navigate('/owner/branches')
    } catch (err) {
      // functional requirements §14.1: a branch with real history (or the
      // primary branch) can't be hard-deleted — the backend's message
      // already says so specifically ("...Deactivate it instead."), so
      // it's shown as-is rather than replaced with a generic one.
      setDeleteError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setDeleting(false)
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/branches">
        <div className="mx-auto flex max-w-3xl flex-col gap-4">
          <button
            type="button"
            className="self-start text-sm text-ink-soft underline-offset-2 hover:underline"
            onClick={() => navigate('/owner/branches')}
          >
            ← Back to branches
          </button>

          {!loaded ? (
            <p className="text-sm text-ink-soft">Loading…</p>
          ) : branch === null ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">Branch not found.</p>
            </Card>
          ) : (
            <>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">{branch.name}</h1>
                  {branch.isPrimary ? <Pill label="Primary" styles="bg-owner-soft text-owner" /> : null}
                  <Pill
                    label={branch.status}
                    styles={branch.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}
                  />
                </div>
                {/* All branch actions live in this header row now (Edit, Activate/Deactivate, Delete) — Delete's confirm swaps in as its own bar below rather than pushing the other buttons around. */}
                <div className="flex shrink-0 flex-wrap gap-2">
                  <Button type="button" variant="secondary" onClick={() => setEditing((v) => !v)}>
                    {editing ? 'Cancel edit' : 'Edit'}
                  </Button>
                  <Button
                    type="button"
                    variant={branch.status === 'active' ? 'danger' : 'secondary'}
                    disabled={statusUpdating}
                    onClick={handleToggleStatus}
                  >
                    {statusUpdating
                      ? branch.status === 'active'
                        ? 'Deactivating…'
                        : 'Activating…'
                      : branch.status === 'active'
                        ? 'Deactivate'
                        : 'Activate'}
                  </Button>
                  {/* Primary branch can never be deleted (every gym must always have exactly one) — no affordance for an action that would only ever 409. */}
                  {!branch.isPrimary ? (
                    <Button type="button" variant="danger" onClick={() => setConfirmingDelete(true)}>
                      Delete
                    </Button>
                  ) : null}
                </div>
              </div>

              {statusError ? <p className="text-sm text-red-600">{statusError}</p> : null}

              {confirmingDelete ? (
                <Card>
                  <p className="text-sm text-ink">Delete {branch.name}? This can't be undone.</p>
                  {deleteError ? <p className="mt-2 text-sm text-red-600">{deleteError}</p> : null}
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => setConfirmingDelete(false)} disabled={deleting}>
                      Cancel
                    </Button>
                    <Button variant="danger" onClick={handleDelete} disabled={deleting}>
                      {deleting ? 'Deleting…' : 'Delete branch'}
                    </Button>
                  </div>
                </Card>
              ) : null}

              {editing ? (
                <EditBranchCard
                  branch={branch}
                  onSaved={async (changes) => {
                    await updateBranch(branch.id, changes)
                    setEditing(false)
                  }}
                />
              ) : (
                <Card>
                  <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <Field label="Address" value={branch.address} />
                    <Field label="Phone" value={branch.phone} />
                  </dl>
                </Card>
              )}

              <div>
                <div className="mb-2 flex items-center justify-between gap-3">
                  <h2 className="text-sm font-semibold text-ink">Assigned Coach / Staff</h2>
                  <Button variant="secondary" onClick={() => setAssigning(true)}>
                    Assign
                  </Button>
                </div>

                {assigning ? (
                  <div className="mb-3">
                    <AssignPanel
                      open={assigning}
                      branch={branch}
                      assignableUsers={assignableUsers}
                      onClose={() => setAssigning(false)}
                      onAssign={assign}
                    />
                  </div>
                ) : null}

                {assignments.length > 0 ? (
                  <div className="mb-3">
                    <Input
                      label="Search"
                      placeholder="Search by name or role"
                      value={assignmentSearch}
                      onChange={(e) => setAssignmentSearch(e.target.value)}
                    />
                  </div>
                ) : null}

                <BranchAssignmentsTable
                  assignments={pagedAssignments}
                  emptyMessage={assignments.length === 0 ? 'No one assigned yet.' : 'No one matches this search.'}
                  onRemove={(userId) => unassign(branch.id, userId)}
                />
                {assignmentsTotal > 0 ? (
                  <div className="mt-3">
                    <Pagination
                      page={assignmentsPage}
                      pageCount={assignmentsPageCount}
                      rangeStart={assignmentsRangeStart}
                      rangeEnd={assignmentsRangeEnd}
                      total={assignmentsTotal}
                      onChange={setAssignmentsPage}
                    />
                  </div>
                ) : null}
              </div>

              <div>
                <div className="mb-2 flex items-center justify-between gap-3">
                  <h2 className="text-sm font-semibold text-ink">Members</h2>
                  <Button variant="secondary" onClick={() => setAssigningMember(true)}>
                    Assign
                  </Button>
                </div>

                {assigningMember ? (
                  <div className="mb-3">
                    <AssignMemberPanel
                      open={assigningMember}
                      branch={branch}
                      assignableMembers={assignableMembers}
                      onClose={() => setAssigningMember(false)}
                      onAssign={assignMember}
                    />
                  </div>
                ) : null}

                {branchMembers.length > 0 ? (
                  <div className="mb-3">
                    <Input
                      label="Search"
                      placeholder="Search by name, email, phone, or member ID"
                      value={memberSearch}
                      onChange={(e) => setMemberSearch(e.target.value)}
                    />
                  </div>
                ) : null}

                <BranchMembersTable
                  members={pagedMembers}
                  loaded={membersLoaded}
                  emptyMessage={
                    branchMembers.length === 0 ? 'No members assigned to this branch yet.' : 'No one matches this search.'
                  }
                  onSelect={(memberId) => navigate(`/owner/members/${memberId}`)}
                  onRemove={(memberId) => unassignMember(branch.id, memberId)}
                />
                {membersTotal > 0 ? (
                  <div className="mt-3">
                    <Pagination
                      page={membersPage}
                      pageCount={membersPageCount}
                      rangeStart={membersRangeStart}
                      rangeEnd={membersRangeEnd}
                      total={membersTotal}
                      onChange={setMembersPage}
                    />
                  </div>
                ) : null}
              </div>

            </>
          )}
        </div>
      </NavShell>
    </div>
  )
}

function Field({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-ink-soft">{label}</dt>
      <dd className="mt-0.5 text-ink">{value ?? '—'}</dd>
    </div>
  )
}

interface BranchMembersTableProps {
  members: MemberListItemDto[]
  loaded: boolean
  emptyMessage: string
  onSelect: (memberId: string) => void
  onRemove: (memberId: string) => void
}

/**
 * This branch's members — card list on mobile, `lg:` table otherwise,
 * same split as OwnerMembersPage's roster (CLAUDE.md: no horizontal page
 * overflow at any viewport, so a table-fixed layout with explicit column
 * widths + break-words, not a shrunk/scrolled table). Coaches are shown
 * separately above (Assigned Coach / Staff), so this list is members
 * only. `members` is already the current page's slice (search-filtered,
 * then paginated) — search and pagination controls render alongside this
 * component, not inside it.
 *
 * Remove only shows for a member whose `assignedBranchId` is set — a
 * member can also appear here purely because their enrolled plan belongs
 * to this branch (the pre-existing informational fallback), which the
 * member-assign facility has nothing to unassign.
 */
function BranchMembersTable({ members, loaded, emptyMessage, onSelect, onRemove }: BranchMembersTableProps) {
  if (loaded && members.length === 0) {
    return (
      <Card>
        <p className="py-6 text-center text-sm text-ink-soft">{emptyMessage}</p>
      </Card>
    )
  }

  return (
    <>
      {/* Card list — default (mobile/tablet) */}
      <div className="flex flex-col gap-3 lg:hidden">
        {members.map((member) => (
          <Card key={member.id}>
            <button type="button" className="w-full text-left" onClick={() => onSelect(member.id)}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-ink underline-offset-2">{member.name}</p>
                  {member.email ? <p className="mt-0.5 text-sm text-ink-soft">{member.email}</p> : null}
                  {member.phone ? <p className="mt-0.5 text-sm text-ink-soft">{member.phone}</p> : null}
                </div>
                <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
              </div>
              {member.membership ? (
                <div className="mt-3 flex items-center gap-2 border-t border-line pt-3 text-sm">
                  <span className="text-ink-soft">{member.membership.planName}</span>
                  <Pill
                    label={member.membership.status}
                    styles={MEMBERSHIP_STATUS_STYLES[member.membership.status] ?? 'bg-gray-100 text-gray-600'}
                  />
                </div>
              ) : null}
            </button>
            {member.assignedBranchId !== null ? (
              <div className="mt-3 border-t border-line pt-3">
                <button
                  type="button"
                  onClick={() => onRemove(member.id)}
                  className="text-xs font-medium text-ink-soft underline hover:text-ink"
                >
                  Remove
                </button>
              </div>
            ) : null}
          </Card>
        ))}
      </div>

      {/* Table — lg: and up */}
      {members.length > 0 ? (
        <table className="hidden w-full table-fixed border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
          <thead>
            <tr className="text-left text-sm text-ink-soft">
              <th className="w-[26%] border-b border-line px-4 py-3">Name</th>
              <th className="w-[10%] border-b border-line px-4 py-3">Member ID</th>
              <th className="w-[22%] border-b border-line px-4 py-3">Contact</th>
              <th className="w-[12%] border-b border-line px-4 py-3">Status</th>
              <th className="w-[18%] border-b border-line px-4 py-3">Plan</th>
              <th className="w-[12%] border-b border-line px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            {members.map((member) => (
              <tr key={member.id} className="text-sm text-ink">
                <td className="border-b border-line/60 px-4 py-3 font-medium break-words">
                  <button
                    type="button"
                    className="underline-offset-2 hover:underline"
                    onClick={() => onSelect(member.id)}
                  >
                    {member.name}
                  </button>
                </td>
                <td className="border-b border-line/60 px-4 py-3 font-mono text-xs whitespace-nowrap text-ink-soft">
                  {member.memberId ?? '—'}
                </td>
                <td className="border-b border-line/60 px-4 py-3 break-words text-ink-soft">
                  {member.email ? <p>{member.email}</p> : null}
                  {member.phone ? <p>{member.phone}</p> : null}
                  {!member.email && !member.phone ? '—' : null}
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
                  ) : (
                    <span className="text-ink-soft">—</span>
                  )}
                </td>
                <td className="border-b border-line/60 px-4 py-3">
                  {member.assignedBranchId !== null ? (
                    <button
                      type="button"
                      onClick={() => onRemove(member.id)}
                      className="text-xs font-medium text-ink-soft underline hover:text-ink"
                    >
                      Remove
                    </button>
                  ) : (
                    <span className="text-ink-soft">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : null}
    </>
  )
}

// Same role palette used elsewhere (coach: DESIGN-SYSTEM.md §4 Badge colors; staff: NotificationBell's neutral gray — Staff has no dedicated brand color).
const ASSIGNMENT_ROLE_STYLES: Record<'coach' | 'staff', string> = {
  coach: 'bg-coach-soft text-coach',
  staff: 'bg-gray-100 text-gray-600',
}

interface BranchAssignmentsTableProps {
  assignments: BranchAssignmentDto[]
  emptyMessage: string
  onRemove: (userId: string) => void
}

/**
 * Coach/Staff assigned to this branch — same card-list/`lg:`-table split
 * as BranchMembersTable, so the two sections read as one consistent
 * pattern rather than a table next to a plain list. `assignments` is
 * already the current page's slice (search-filtered, then paginated) —
 * search and pagination controls render alongside this component, not
 * inside it.
 */
function BranchAssignmentsTable({ assignments, emptyMessage, onRemove }: BranchAssignmentsTableProps) {
  if (assignments.length === 0) {
    return (
      <Card>
        <p className="py-6 text-center text-sm text-ink-soft">{emptyMessage}</p>
      </Card>
    )
  }

  return (
    <>
      {/* Card list — default (mobile/tablet) */}
      <div className="flex flex-col gap-3 lg:hidden">
        {assignments.map((a) => (
          <Card key={a.userId}>
            <div className="flex items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <span className="text-sm font-semibold text-ink">{a.name}</span>
                <Pill label={a.role} styles={ASSIGNMENT_ROLE_STYLES[a.role]} />
              </div>
              <button
                type="button"
                onClick={() => onRemove(a.userId)}
                className="text-xs font-medium text-ink-soft underline hover:text-ink"
              >
                Remove
              </button>
            </div>
          </Card>
        ))}
      </div>

      {/* Table — lg: and up */}
      <table className="hidden w-full table-fixed border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
        <thead>
          <tr className="text-left text-sm text-ink-soft">
            <th className="w-[50%] border-b border-line px-4 py-3">Name</th>
            <th className="w-[25%] border-b border-line px-4 py-3">Role</th>
            <th className="w-[25%] border-b border-line px-4 py-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          {assignments.map((a) => (
            <tr key={a.userId} className="text-sm text-ink">
              <td className="border-b border-line/60 px-4 py-3 font-medium break-words">{a.name}</td>
              <td className="border-b border-line/60 px-4 py-3">
                <Pill label={a.role} styles={ASSIGNMENT_ROLE_STYLES[a.role]} />
              </td>
              <td className="border-b border-line/60 px-4 py-3">
                <button
                  type="button"
                  onClick={() => onRemove(a.userId)}
                  className="text-xs font-medium text-ink-soft underline hover:text-ink"
                >
                  Remove
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  )
}

interface EditBranchCardProps {
  branch: BranchDto
  onSaved: (changes: { name?: string; address?: string; phone?: string }) => Promise<void>
}

function EditBranchCard({ branch, onSaved }: EditBranchCardProps) {
  const [name, setName] = useState(branch.name)
  const [address, setAddress] = useState(branch.address)
  const [phone, setPhone] = useState(branch.phone ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit() {
    if (name.trim() === '' || address.trim() === '') {
      setError('Name and address are required.')
      return
    }

    setSubmitting(true)
    setError(null)
    try {
      await onSaved({ name: name.trim(), address: address.trim(), phone: phone.trim() || undefined })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <div className="flex flex-col gap-4">
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label="Address" value={address} onChange={(e) => setAddress(e.target.value)} required />
        <Input label="Phone" hint="Optional" value={phone} onChange={(e) => setPhone(e.target.value)} />
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button onClick={handleSubmit} disabled={submitting}>
          {submitting ? 'Saving…' : 'Save changes'}
        </Button>
      </div>
    </Card>
  )
}

interface AssignPanelProps {
  open: boolean
  branch: BranchDto
  assignableUsers: { id: string; name: string; role: 'coach' | 'staff' }[]
  onClose: () => void
  onAssign: (branchId: string, userId: string) => Promise<BranchDto>
}

function AssignPanel({ open, branch, assignableUsers, onClose, onAssign }: AssignPanelProps) {
  const unassigned = assignableUsers.filter((u) => !branch.assignments.some((a) => a.userId === u.id))
  const [userId, setUserId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!open) return null

  const selectedUserId = userId || unassigned[0]?.id || ''

  function handleClose() {
    setUserId('')
    setError(null)
    onClose()
  }

  async function handleSubmit() {
    if (!selectedUserId) return
    setSubmitting(true)
    setError(null)
    try {
      await onAssign(branch.id, selectedUserId)
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-ink">Assign to {branch.name}</h2>
          <button type="button" className="text-xs font-medium text-ink-soft underline hover:text-ink" onClick={handleClose}>
            Cancel
          </button>
        </div>

        {unassigned.length === 0 ? (
          <p className="text-sm text-ink-soft">Every Coach/Staff account is already assigned here.</p>
        ) : (
          <>
            <Select
              label="Coach or Staff"
              value={selectedUserId}
              onChange={(e) => setUserId(e.target.value)}
              options={unassigned.map((u) => ({ value: u.id, label: `${u.name} (${u.role})` }))}
            />
            {error ? <p className="text-sm text-red-600">{error}</p> : null}
            <Button onClick={handleSubmit} disabled={submitting}>
              {submitting ? 'Assigning…' : 'Assign'}
            </Button>
          </>
        )}
      </div>
    </Card>
  )
}

interface AssignMemberPanelProps {
  open: boolean
  branch: BranchDto
  assignableMembers: { id: string; name: string }[]
  onClose: () => void
  onAssign: (branchId: string, userId: string) => Promise<BranchDto>
}

/**
 * Owner-set "home branch" tag for a Member — same shape as AssignPanel
 * above, but a Member has at most one assigned branch at a time (unlike
 * Coach/Staff's many), so picking someone already assigned elsewhere
 * just moves them rather than being excluded from the list or erroring.
 */
function AssignMemberPanel({ open, branch, assignableMembers, onClose, onAssign }: AssignMemberPanelProps) {
  const [userId, setUserId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!open) return null

  const selectedUserId = userId || assignableMembers[0]?.id || ''

  function handleClose() {
    setUserId('')
    setError(null)
    onClose()
  }

  async function handleSubmit() {
    if (!selectedUserId) return
    setSubmitting(true)
    setError(null)
    try {
      await onAssign(branch.id, selectedUserId)
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-ink">Assign to {branch.name}</h2>
          <button type="button" className="text-xs font-medium text-ink-soft underline hover:text-ink" onClick={handleClose}>
            Cancel
          </button>
        </div>

        {assignableMembers.length === 0 ? (
          <p className="text-sm text-ink-soft">Every active Member is already assigned here.</p>
        ) : (
          <>
            <Select
              label="Member"
              value={selectedUserId}
              onChange={(e) => setUserId(e.target.value)}
              options={assignableMembers.map((m) => ({ value: m.id, label: m.name }))}
            />
            {error ? <p className="text-sm text-red-600">{error}</p> : null}
            <Button onClick={handleSubmit} disabled={submitting}>
              {submitting ? 'Assigning…' : 'Assign'}
            </Button>
          </>
        )}
      </div>
    </Card>
  )
}

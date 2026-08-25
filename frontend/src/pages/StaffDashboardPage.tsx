import { useEffect, useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Pagination } from '../components/ui'
import { CheckInIcon } from '../components/ui/icons'
import { KpiCard, AlertCard, ActivityFeed } from '../components/dashboard'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { usePagination } from '../lib/usePagination'
import { useMembers } from '../members/useMembers'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'
import { useStaffDashboard } from '../dashboard/useStaffDashboard'
import { useBranchBillingAttention } from '../billing/useBranchBillingAttention'
import { RecordPaymentModal } from '../billing/RecordPaymentModal'
import type { MemberAccountStatus, MemberListItemDto } from '../members/types'
import type { AttentionInvoiceDto } from '../billing/types'

const PAGE_SIZE = 20

// Same palette as OwnerMembersPage's Pill — one status vocabulary
// across every screen that shows account/membership status.
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

const BLOCKED_REASON_LABELS: Record<string, string> = {
  membership_expired: 'Membership expired',
  membership_paused: 'Membership paused',
  membership_cancelled: 'Membership cancelled',
  account_suspended: 'Account suspended',
  no_membership: 'No membership',
  // gym-management-billing-v1.md §5.5/§7 — plain-language check-in-blocked reasons for billing gating.
  subscription_inactive: 'Membership suspended',
  absent_invoice: 'Payment missed',
  overdue: 'Payment overdue',
}

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

interface CheckInRowState {
  status: 'checking' | 'success' | 'blocked' | 'error'
  message: string
}

function matchesSearch(member: MemberListItemDto, query: string): boolean {
  if (query === '') return true
  const haystack = [member.name, member.email, member.phone].filter(Boolean).join(' ').toLowerCase()

  return haystack.includes(query)
}

/**
 * roadmap Phase 15.1: Staff's whole app — a scoped-down Owner view.
 * Read-only member list (no suspend/enroll actions, those stay behind
 * MemberVoter::MANAGE which has no Staff branch) plus a front-desk
 * check-in button per row, calling the new POST /members/:id/checkin.
 */
export function StaffDashboardPage() {
  const { user, authFetch } = useAuth()
  const { members, loaded } = useMembers()
  const { branches } = useBranches()
  const [search, setSearch] = useState('')
  const [rowState, setRowState] = useState<Record<string, CheckInRowState>>({})
  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)

  // functional requirements §14.2 / DESIGN-SYSTEM.md §4.2: Staff only
  // ever picks among branches THEY'RE assigned to — never "all branches"
  // (that option is Owner-reports-only), and genuinely absent from the
  // UI if they're only assigned to one.
  const myBranches = useMemo(
    () => branches.filter((b) => b.assignments.some((a) => a.userId === user?.id)),
    [branches, user?.id],
  )
  const activeBranchId = selectedBranchId ?? defaultBranchId(myBranches)
  const { summary, loaded: dashboardLoaded, error: dashboardError } = useStaffDashboard(activeBranchId)
  const { invoices: attentionInvoices, loaded: attentionLoaded, refresh: refreshAttention } = useBranchBillingAttention(activeBranchId)
  const [payingInvoice, setPayingInvoice] = useState<AttentionInvoiceDto | null>(null)

  const visibleMembers = useMemo(() => {
    const query = search.trim().toLowerCase()

    return members.filter((member) => member.role === 'member' && matchesSearch(member, query))
  }, [members, search])

  const { page, pageCount, paged: pagedMembers, rangeStart, rangeEnd, total, setPage } = usePagination(
    visibleMembers,
    PAGE_SIZE,
  )

  // A new search query can shrink the result set or reorder it entirely —
  // always land back on page 1 rather than risk stranding Staff on a
  // now-empty or now-mismatched page.
  useEffect(() => {
    setPage(1)
  }, [search, setPage])

  async function handleCheckIn(member: MemberListItemDto) {
    setRowState((prev) => ({ ...prev, [member.id]: { status: 'checking', message: '' } }))

    try {
      const result = await authFetch<{ checkInAt: string }>(`/members/${member.id}/checkin`, {
        method: 'POST',
        body: { branchId: activeBranchId },
      })
      const time = new Date(result.checkInAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
      setRowState((prev) => ({ ...prev, [member.id]: { status: 'success', message: `Checked in at ${time}` } }))
    } catch (err) {
      if (err instanceof ApiError && err.code === 'checkin_blocked' && err.reason) {
        setRowState((prev) => ({
          ...prev,
          [member.id]: { status: 'blocked', message: BLOCKED_REASON_LABELS[err.reason as string] ?? 'Check-in blocked' },
        }))
      } else {
        setRowState((prev) => ({ ...prev, [member.id]: { status: 'error', message: "Couldn't check in." } }))
      }
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="staff" title="Gym" navItems={STAFF_NAV_ITEMS} activeHref="/staff/members">
        {/* Wider than the old single max-w-2xl column: the member list
            (Staff's actual working area) gets the wider lg: column so
            more of each row's info/actions fit without truncating, while
            the summary widgets stay narrower alongside it instead of
            pushing the list far down the page. Both stack to one column
            below lg:, unchanged from before. */}
        <div className="mx-auto grid max-w-6xl grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
          <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
              <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Members</h1>
              {/* Absent entirely when Staff is assigned to only one branch (DESIGN-SYSTEM.md §4.2). */}
              <BranchSwitcher branches={myBranches} value={activeBranchId} onChange={setSelectedBranchId} />
            </div>

            {/* gym-management-dashboard-redesign.md Phase 4: check-ins/expiring-memberships/activity widgets, scoped to whichever branch is selected above — re-fetch on branch change via useStaffDashboard's own dependency on activeBranchId. */}
            {dashboardError ? (
              <AlertCard tone="danger" title="Couldn't load dashboard">
                {dashboardError}
              </AlertCard>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <KpiCard label="Check-ins today" value={!dashboardLoaded || !summary ? '—' : summary.todayCheckins} />
                  <KpiCard label="Expiring soon" value={!dashboardLoaded || !summary ? '—' : summary.expiringMembershipsCount} hint="Next 7 days" />
                </div>

                {dashboardLoaded && summary && summary.expiringMembershipsCount > 0 ? (
                  <AlertCard tone="warning" title={`${summary.expiringMembershipsCount} membership${summary.expiringMembershipsCount === 1 ? '' : 's'} expiring soon`}>
                    <ul className="list-inside list-disc">
                      {summary.expiringMemberships.map((m) => (
                        <li key={m.memberId}>
                          {m.memberName} — {new Date(m.endDate).toLocaleDateString([], { month: 'short', day: 'numeric' })}
                        </li>
                      ))}
                    </ul>
                  </AlertCard>
                ) : null}

                {/* gym-management-billing-v1.md §7: "needs attention" widget — absent/overdue invoices, oldest due first, with a direct record-payment action. */}
                {attentionLoaded && attentionInvoices.length > 0 ? (
                  <AlertCard tone="danger" title={`${attentionInvoices.length} payment${attentionInvoices.length === 1 ? '' : 's'} need attention`}>
                    <ul className="flex flex-col gap-2">
                      {attentionInvoices.map((invoice) => (
                        <li key={invoice.id} className="flex items-center justify-between gap-2">
                          <span>
                            {invoice.member.name} — <span className="font-mono">${invoice.amount}</span>{' '}
                            <span className="uppercase">{invoice.status}</span>
                          </span>
                          <Button variant="secondary" onClick={() => setPayingInvoice(invoice)}>
                            Record payment
                          </Button>
                        </li>
                      ))}
                    </ul>
                  </AlertCard>
                ) : null}

                {dashboardLoaded && summary ? (
                  <Card>
                    <h2 className="mb-3 text-base font-semibold text-ink">Today's check-ins</h2>
                    <ActivityFeed
                      items={summary.recentActivity.map((a) => ({ id: a.id, label: a.memberName, timestamp: a.checkInAt }))}
                      emptyMessage="No check-ins yet today."
                    />
                  </Card>
                ) : null}
              </>
            )}
          </div>

          <div className="flex flex-col gap-4 lg:col-span-2">
            <Input
              label="Search"
              placeholder="Search by name, email, or phone"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />

            {loaded && visibleMembers.length === 0 ? (
              <Card>
                <p className="py-6 text-center text-sm text-ink-soft">
                  {members.length === 0 ? 'No members yet.' : 'No one matches this search.'}
                </p>
              </Card>
            ) : null}

            <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
              {pagedMembers.map((member) => {
              const state = rowState[member.id]

              return (
                <Card key={member.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-ink">{member.name}</p>
                      <p className="truncate text-sm text-ink-soft">{member.email ?? member.phone}</p>
                    </div>
                    <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
                  </div>

                  <div className="mt-3 flex items-center justify-between gap-3">
                    {member.membership ? (
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-ink-soft">{member.membership.planName}</span>
                        <Pill
                          label={member.membership.status}
                          styles={MEMBERSHIP_STATUS_STYLES[member.membership.status] ?? 'bg-gray-100 text-gray-600'}
                        />
                      </div>
                    ) : (
                      <span className="text-sm text-ink-soft">No plan</span>
                    )}

                    <Button
                      variant="secondary"
                      onClick={() => handleCheckIn(member)}
                      disabled={state?.status === 'checking'}
                    >
                      <CheckInIcon />
                      {state?.status === 'checking' ? 'Checking in…' : 'Check in'}
                    </Button>
                  </div>

                  {state && state.status !== 'checking' ? (
                    <p
                      className={`mt-2 text-sm ${
                        state.status === 'success'
                          ? 'text-green-700'
                          : state.status === 'blocked'
                            ? 'text-red-700'
                            : 'text-ink-soft'
                      }`}
                    >
                      {state.message}
                    </p>
                  ) : null}
                </Card>
              )
            })}
            </div>

            <Pagination page={page} pageCount={pageCount} rangeStart={rangeStart} rangeEnd={rangeEnd} total={total} onChange={setPage} />
          </div>
        </div>

        <RecordPaymentModal
          invoice={payingInvoice ? { id: payingInvoice.id, amount: payingInvoice.amount, memberName: payingInvoice.member.name } : null}
          onClose={() => setPayingInvoice(null)}
          onRecorded={() => void refreshAttention()}
        />
      </NavShell>
    </div>
  )
}

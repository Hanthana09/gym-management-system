import { useState } from 'react'
import { Link } from 'react-router-dom'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS, MEMBER_NAV_ITEMS, OWNER_NAV_ITEMS, STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card } from '../components/ui'
import { CheckInIcon, MembersIcon } from '../components/ui/icons'
import { ActivityFeed, AlertCard, ChartCard, ExpenseCategoryDonutChart, KpiCard } from '../components/dashboard'
import { useAuth } from '../auth/AuthContext'
import { OwnerInvitationsPanel } from '../invitations/OwnerInvitationsPanel'
import { MyInvitationsPanel } from '../invitations/MyInvitationsPanel'
import { MyMembershipCard } from '../membership/MyMembershipCard'
import { NotificationPreferences } from '../notifications/NotificationPreferences'
import { useMemberDashboard } from '../dashboard/useMemberDashboard'
import { useOwnerDashboard } from '../dashboard/useOwnerDashboard'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'
import { useBranchBillingAttention } from '../billing/useBranchBillingAttention'
import { useMemberBillingStatus } from '../billing/useMemberBillingStatus'
import { RecordPaymentModal } from '../billing/RecordPaymentModal'
import type { AttentionInvoiceDto } from '../billing/types'

const NAV_ITEMS = { owner: OWNER_NAV_ITEMS, coach: COACH_NAV_ITEMS, member: MEMBER_NAV_ITEMS, staff: STAFF_NAV_ITEMS }

/**
 * Still a placeholder landing page (a full per-role dashboard is a later
 * phase), but now carries Phase 3's invitation UI, Phase 4's membership
 * UI, and Phase 5's entry points: Owner gets a link to the live
 * attendance dashboard, Member gets a prominent Check-in link (the actual
 * check-in screen has its own bottom nav from there on). Wrapped in
 * NavShell like every other authenticated page — this is the first
 * screen after login, so skipping it here meant the sidebar/bottom-nav
 * appeared to vanish the moment you signed in.
 */
export function HomePage() {
  const { user, logout } = useAuth()
  if (!user) return null

  return (
    <div className="h-dvh">
      <NavShell role={user.role} title="Gym" navItems={NAV_ITEMS[user.role]} activeHref="/">
        {/*
         * Wide-screen layout (lg:+): main content (KPIs/charts/panels —
         * whatever's most information-dense for this role) gets 2/3 of
         * the width, secondary content (quick links, account) gets 1/3,
         * side by side instead of one long narrow column. Below lg:, both
         * stack to a single column exactly as before — mobile-first still
         * holds, this only changes how the extra space on a wide screen
         * gets used.
         */}
        <div className="mx-auto grid max-w-6xl grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
          <div className="flex flex-col gap-4 lg:col-span-2">
            {user.role === 'owner' ? (
              <>
                <OwnerDashboardWidgets />
                <OwnerInvitationsPanel />
              </>
            ) : (
              <>
                <MyInvitationsPanel />
                {user.role === 'member' ? (
                  <>
                    <Link to="/member/check-in">
                      <Button fullWidth className="!min-h-16 !text-base">
                        <CheckInIcon />
                        Check In
                      </Button>
                    </Link>
                    <MyMembershipCard />
                    <MemberDashboardWidgets />
                  </>
                ) : null}
                {user.role === 'coach' ? (
                  <Link to="/coach/sessions">
                    <Card className="border-2 border-ink transition-colors hover:bg-paper-dim">
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-semibold text-ink">My schedule</span>
                        <span className="text-sm text-ink-soft">Session requests →</span>
                      </div>
                    </Card>
                  </Link>
                ) : null}
                {user.role === 'staff' ? (
                  <Link to="/staff/members">
                    <Card className="border-2 border-ink transition-colors hover:bg-paper-dim">
                      <div className="flex items-center justify-between">
                        <span className="flex items-center gap-2 text-sm font-semibold text-ink">
                          <MembersIcon />
                          Members
                        </span>
                        <span className="text-sm text-ink-soft">View & check in →</span>
                      </div>
                    </Card>
                  </Link>
                ) : null}
              </>
            )}
          </div>

          <div className="flex flex-col gap-4">
            {user.role === 'owner' ? (
              <>
                <Link to="/owner/dashboard">
                  <Card className="transition-colors hover:bg-paper-dim">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium text-ink">Full reports</span>
                      <span className="text-sm text-ink-soft">Trends, forecast, export →</span>
                    </div>
                  </Card>
                </Link>
                {/* Not in OWNER_NAV_ITEMS (sidebar nav stays unchanged, per this phase's own rule) — these two are otherwise unreachable, so they stay here rather than in the removed shortcut grid. */}
                <Link to="/owner/import">
                  <Card className="transition-colors hover:bg-paper-dim">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium text-ink">Bulk Import Members</span>
                      <span className="text-sm text-ink-soft">Upload CSV →</span>
                    </div>
                  </Card>
                </Link>
                <Link to="/owner/referrals">
                  <Card className="transition-colors hover:bg-paper-dim">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium text-ink">Referrals</span>
                      <span className="text-sm text-ink-soft">Your code →</span>
                    </div>
                  </Card>
                </Link>
              </>
            ) : null}
            {user.role === 'member' ? (
              <Link to="/member/invoices">
                <Card className="transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">Billing</span>
                    <span className="text-sm text-ink-soft">Invoice history →</span>
                  </div>
                </Card>
              </Link>
            ) : null}
            {user.role === 'coach' ? (
              <Link to="/coach/refer">
                <Card className="transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">Recommend this to another gym</span>
                    <span className="text-sm text-ink-soft">→</span>
                  </div>
                </Card>
              </Link>
            ) : null}

            <Card>
              <div className="text-center">
                <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Signed in</h1>
                <p className="mt-1 text-sm text-ink-soft">
                  {user.name} · {user.email ?? user.phone} · <span className="capitalize">{user.role}</span>
                </p>
              </div>
              <div className="mt-4">
                <NotificationPreferences />
              </div>
              <Button className="mt-4" fullWidth variant="secondary" onClick={logout}>
                Log out
              </Button>
            </Card>
          </div>
        </div>
      </NavShell>
    </div>
  )
}

/**
 * gym-management-dashboard-redesign.md Phase 4: Owner's at-a-glance KPIs
 * right on the home view — the same numbers /owner/dashboard's Reports
 * page shows, surfaced here too so an Owner doesn't have to click
 * through just to see today's basics. `allowAll` matches
 * OwnerDashboardPage's own reports-default-to-gym-wide-rollup rule
 * (DESIGN-SYSTEM.md §4.2).
 */
function OwnerDashboardWidgets() {
  const { branches } = useBranches()
  const [branchId, setBranchId] = useState<string | null>(null)
  const { summary, loaded } = useOwnerDashboard(branchId)
  // The billing-attention endpoint (gym-management-billing-v1.md §6) needs
  // one concrete branch — "All branches" here falls back to the default
  // one, same resolution StaffDashboardPage/front-desk check-in already use.
  const attentionBranchId = branchId ?? defaultBranchId(branches)
  const { invoices: attentionInvoices, loaded: attentionLoaded, refresh: refreshAttention } = useBranchBillingAttention(attentionBranchId)
  const [payingInvoice, setPayingInvoice] = useState<AttentionInvoiceDto | null>(null)

  return (
    <>
      <Card>
        <div className="mb-3 flex items-center justify-end">
          <BranchSwitcher branches={branches} value={branchId} onChange={setBranchId} allowAll />
        </div>
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <KpiCard
            label="Check-ins today"
            value={!loaded || !summary ? '—' : summary.todayCheckins}
            trend={loaded && summary ? summary.checkinsTrend : undefined}
          />
          <KpiCard
            label="Revenue today"
            value={!loaded || !summary ? '—' : `$${summary.todayRevenue}`}
            trend={loaded && summary ? summary.revenueTrend : undefined}
          />
          <KpiCard
            label="Active members"
            value={!loaded || !summary ? '—' : summary.activeMembersCount}
            trend={loaded && summary ? summary.activeMembersTrend : undefined}
          />
        </div>
      </Card>

      {/* gym-management-billing-v1.md §7: "needs attention" — absent/overdue invoices, oldest due first, with a direct record-payment action. Answers "what needs my attention today" right here, not on a separate billing page. */}
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

      {loaded && summary && summary.expensesByCategory.length > 0 ? (
        <ChartCard title="Expenses by category">
          <ExpenseCategoryDonutChart data={summary.expensesByCategory} />
        </ChartCard>
      ) : null}

      <RecordPaymentModal
        invoice={payingInvoice ? { id: payingInvoice.id, amount: payingInvoice.amount, memberName: payingInvoice.member.name } : null}
        onClose={() => setPayingInvoice(null)}
        onRecorded={() => void refreshAttention()}
      />
    </>
  )
}

/**
 * gym-management-dashboard-redesign.md Phase 4: Member's next PT session
 * and recent attendance, each row tagged with the branch it happened at
 * — a member training at two branches can tell them apart at a glance.
 * No BranchSelector here (Member stays hub-wide by design); the streak
 * itself is deliberately not shown here to keep this section short —
 * the attendance history page is the place for a fuller view.
 */
function MemberDashboardWidgets() {
  const { user } = useAuth()
  const { summary, loaded } = useMemberDashboard()
  const { status: billingStatus, loaded: billingLoaded } = useMemberBillingStatus(user?.id ?? null)

  if (!loaded || !summary) return null

  return (
    <>
      {/* gym-management-billing-v1.md §7: current billing status + next due date, read-only. Absent entirely for a member with no recurring subscription. */}
      {billingLoaded && billingStatus && billingStatus.subscriptionStatus !== null ? (
        <Card>
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-ink">Billing</p>
            {billingStatus.blockReason ? (
              <span className="rounded-full bg-member-soft px-2 py-0.5 font-mono text-xs tracking-wide text-member uppercase">
                {billingStatus.blockReason.replace(/_/g, ' ')}
              </span>
            ) : (
              <span className="rounded-full bg-green-100 px-2 py-0.5 font-mono text-xs tracking-wide text-green-800 uppercase">
                Current
              </span>
            )}
          </div>
          {billingStatus.outstandingInvoices[0]?.dueDate ? (
            <p className="mt-1 text-sm text-ink-soft">
              Next due {new Date(billingStatus.outstandingInvoices[0].dueDate).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })}
            </p>
          ) : null}
        </Card>
      ) : null}

      <MemberDashboardActivity summary={summary} />
    </>
  )
}

function MemberDashboardActivity({ summary }: { summary: NonNullable<ReturnType<typeof useMemberDashboard>['summary']> }) {
  return (
    <Card>
      {summary.nextSession ? (
        <div className="mb-3 flex items-center justify-between gap-3 border-b border-line pb-3">
          <div>
            <p className="text-sm font-medium text-ink">Next session</p>
            <p className="text-sm text-ink-soft">
              {new Date(summary.nextSession.scheduledAt).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
            </p>
          </div>
          <span className="rounded-full bg-paper-dim px-2 py-0.5 font-mono text-xs tracking-wide text-ink-soft uppercase">
            {summary.nextSession.branch.name}
          </span>
        </div>
      ) : null}

      <p className="mb-2 text-sm font-medium text-ink">Recent attendance</p>
      <ActivityFeed
        items={summary.recentAttendance.map((a) => ({ id: a.id, label: 'Checked in', timestamp: a.checkInAt, tag: a.branch.name }))}
        emptyMessage="No check-ins yet."
      />
    </Card>
  )
}

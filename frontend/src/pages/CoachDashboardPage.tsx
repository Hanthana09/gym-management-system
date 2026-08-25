import { useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS } from '../components/nav-items'
import { Card } from '../components/ui'
import { KpiCard, AlertCard, ActivityFeed, ChartCard, ProgressBar } from '../components/dashboard'
import { useAuth } from '../auth/AuthContext'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'
import { useCoachDashboard } from '../dashboard/useCoachDashboard'

/**
 * gym-management-dashboard-redesign.md Phase 4: Coach's real home view,
 * replacing the ComingSoonPage stub — BranchSelector (hidden if only one
 * assigned branch), today's session list, assigned-members count, and a
 * weekly utilization bar. Switching branches re-fetches; it never blends
 * two branches' data into one view (useCoachDashboard re-runs whenever
 * `activeBranchId` changes).
 */
export function CoachDashboardPage() {
  const { user } = useAuth()
  const { branches } = useBranches()
  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)

  const myBranches = useMemo(
    () => branches.filter((b) => b.assignments.some((a) => a.userId === user?.id)),
    [branches, user?.id],
  )
  const activeBranchId = selectedBranchId ?? defaultBranchId(myBranches)
  const { summary, loaded, error } = useCoachDashboard(activeBranchId)

  return (
    <div className="h-dvh">
      <NavShell role="coach" title="Gym" navItems={COACH_NAV_ITEMS} activeHref="/coach/dashboard">
        <div className="mx-auto flex max-w-6xl flex-col gap-4">
          <div className="flex items-center gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Dashboard</h1>
            {/* Absent entirely when this Coach is assigned to only one branch (DESIGN-SYSTEM.md §4.2). */}
            <BranchSwitcher branches={myBranches} value={activeBranchId} onChange={setSelectedBranchId} />
          </div>

          {error ? (
            <AlertCard tone="danger" title="Couldn't load dashboard">
              {error}
            </AlertCard>
          ) : !loaded || !summary ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
            </Card>
          ) : (
            // Same main+secondary split as StaffDashboardPage: today's
            // session list is the actual work list, so it gets the wider
            // lg: column; KPIs/utilization stay narrower alongside it.
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
              <div className="flex flex-col gap-4 lg:order-2 lg:col-span-1">
                <div className="grid grid-cols-2 gap-4">
                  <KpiCard label="Sessions today" value={summary.todaySessions.length} />
                  <KpiCard label="Assigned members" value={summary.assignedMembersCount} />
                </div>

                <ChartCard title="This week's utilization">
                  <ProgressBar
                    percentage={summary.weeklyUtilization.percentage}
                    label={`${summary.weeklyUtilization.sessionsThisWeek} session${summary.weeklyUtilization.sessionsThisWeek === 1 ? '' : 's'} this week`}
                  />
                </ChartCard>
              </div>

              <Card className="lg:order-1 lg:col-span-2">
                <h2 className="mb-3 text-base font-semibold text-ink">Today's sessions</h2>
                <ActivityFeed
                  items={summary.todaySessions.map((s) => ({
                    id: s.id,
                    label: s.memberName,
                    timestamp: s.scheduledAt,
                  }))}
                  emptyMessage="No sessions scheduled today."
                />
              </Card>
            </div>
          )}
        </div>
      </NavShell>
    </div>
  )
}

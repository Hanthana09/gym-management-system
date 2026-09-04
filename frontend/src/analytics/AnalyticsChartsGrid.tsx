import { AtRiskSparkline } from './AtRiskSparkline'
import { BranchComparisonChart } from './BranchComparisonChart'
import { MembershipHealthChart } from './MembershipHealthChart'
import { NewVsReturningChart } from './NewVsReturningChart'
import { PeakHoursHeatmap } from './PeakHoursHeatmap'
import { RevenueTrendChart } from './RevenueTrendChart'

/**
 * The six home-dashboard chart widgets (Owner analytics slice, roadmap
 * Phase 11), rendered as a responsive grid above the reports tabs on the
 * Owner's home screen.
 *
 * Branch-scope split (confirmed design intent):
 *   - branch-scoped (follow the BranchSwitcher): peak hours, membership health
 *   - hub-wide (ignore the selector): revenue trend, branch comparison,
 *     at-risk trend, new vs returning
 *
 * Branch comparison only makes sense with more than one branch, so it's
 * omitted for single-branch gyms — same rule the BranchSwitcher itself
 * uses (functional requirements §14.1).
 */
export function AnalyticsChartsGrid({ branchId, branchCount }: { branchId: string | null; branchCount: number }) {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      <RevenueTrendChart />
      <MembershipHealthChart branchId={branchId} />
      <PeakHoursHeatmap branchId={branchId} />
      <AtRiskSparkline />
      <NewVsReturningChart />
      {branchCount > 1 ? <BranchComparisonChart /> : null}
    </div>
  )
}

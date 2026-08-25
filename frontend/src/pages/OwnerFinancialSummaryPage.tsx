import { useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Card, Input } from '../components/ui'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher } from '../branches/BranchSwitcher'
import { useFinancialSummary } from '../expenses/useFinancialSummary'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function daysAgo(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)

  return date.toISOString().slice(0, 10)
}

function StatTile({ label, value, tone }: { label: string; value: string; tone?: 'positive' | 'negative' }) {
  return (
    <Card>
      <p className="text-sm text-ink-soft">{label}</p>
      <p
        className={
          'mt-1 font-mono text-2xl font-semibold tabular-nums ' +
          (tone === 'positive' ? 'text-green-700' : tone === 'negative' ? 'text-red-700' : 'text-ink')
        }
      >
        ${value}
      </p>
    </Card>
  )
}

/**
 * functional requirements §15.4 / roadmap Phase 17: Owner-only financial
 * summary — membership + PT + retail revenue vs. total expenses, net
 * total, filterable by date range and branch. Gated server-side by the
 * existing ReportVoter::VIEW (Staff/Coach/Member get 403, no new Voter).
 * Branch selector reuses the Phase 16 BranchSwitcher primitive
 * (DESIGN-SYSTEM.md §4.2), defaulting to the gym-wide rollup like every
 * other Owner report (functional requirements §14.5). All currency in
 * `font-mono` per DESIGN-SYSTEM.md §2.
 */
export function OwnerFinancialSummaryPage() {
  const { branches } = useBranches()
  const [branchId, setBranchId] = useState<string | null>(null)
  const [from, setFrom] = useState(daysAgo(30))
  const [to, setTo] = useState(today())
  const { summary, loading } = useFinancialSummary(from, to, branchId)

  const net = summary ? Number(summary.net) : 0

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/finance">
        <div className="mx-auto flex max-w-5xl flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Finance</h1>
            {/* functional requirements §15.4/§14.5: absent for single-branch gyms; defaults to "All branches" otherwise. */}
            <BranchSwitcher branches={branches} value={branchId} onChange={setBranchId} allowAll />
          </div>

          <Card>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
          </Card>

          {loading || summary === null ? (
            <p className="text-sm text-ink-soft">Loading…</p>
          ) : (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatTile label="Membership revenue" value={summary.membershipRevenue} />
                <StatTile label="PT revenue" value={summary.ptRevenue} />
                <StatTile label="Retail revenue" value={summary.retailRevenue} />
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatTile label="Total expenses" value={summary.totalExpenses} tone="negative" />
                <StatTile label="Net" value={summary.net} tone={net >= 0 ? 'positive' : 'negative'} />
              </div>
              <p className="text-xs text-ink-soft">
                Net = membership + PT + retail revenue − total expenses, for{' '}
                {branchId ? branches.find((b) => b.id === branchId)?.name ?? 'the selected branch' : 'all branches'} between{' '}
                {from} and {to}.
              </p>
            </>
          )}
        </div>
      </NavShell>
    </div>
  )
}

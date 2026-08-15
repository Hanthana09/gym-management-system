import { useEffect, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Pagination, Select, Tabs, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { usePagination } from '../lib/usePagination'
import { useLiveAttendanceCount } from '../attendance/useLiveAttendanceCount'
import { useAttendanceReport } from '../attendance/useAttendanceReport'
import { useDashboardSummary } from '../analytics/useDashboardSummary'
import { useRevenueForecast } from '../analytics/useRevenueForecast'
import { useRetention } from '../analytics/useRetention'
import { useExportReport } from '../analytics/useExportReport'
import { AttendanceTrendChart } from '../analytics/AttendanceTrendChart'
import { RevenueForecastChart } from '../analytics/RevenueForecastChart'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher } from '../branches/BranchSwitcher'
import type { ExportFormat, ExportReportType } from '../analytics/types'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function daysAgo(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)

  return date.toISOString().slice(0, 10)
}

const HORIZON_OPTIONS: { value: string; label: string }[] = [
  { value: '30', label: '30 days' },
  { value: '60', label: '60 days' },
  { value: '90', label: '90 days' },
]

/** Both the Attendance and At-risk members tabs paginate at this size — long lists were hard to scan before this. */
const PAGE_SIZE = 20

type DashboardTab = 'attendance' | 'revenue' | 'retention' | 'export'

const DASHBOARD_TABS: { value: DashboardTab; label: string }[] = [
  { value: 'attendance', label: 'Attendance' },
  { value: 'revenue', label: 'Revenue' },
  { value: 'retention', label: 'At-risk members' },
  { value: 'export', label: 'Export' },
]

/**
 * roadmap Phase 5 (live counter + date-range report) extended in Phase 11
 * with the aggregated trend/forecast/retention/export views — "Dashboard:
 * live counters alongside the aggregated trend/forecast views," per the
 * roadmap, rather than a separate screen. Every number and chart below
 * REPORT_VIEW-gates through ReportVoter server-side; this page just
 * renders what the API already scoped to the Owner's own gym.
 *
 * The three "today" stat cards stay always visible above the tabs — the
 * roadmap's own framing is "live counters alongside the aggregated
 * views," i.e. at-a-glance context, not something to bury a click away.
 * Everything below (date-range attendance, forecast, retention, export)
 * was a long vertical stack of unrelated sections; tabs group each into
 * its own view instead.
 */
export function OwnerDashboardPage() {
  const { branches } = useBranches()
  // functional requirements §14.5: reports default to the gym-wide rollup
  // (null), not a single branch — the opposite default from front-desk
  // check-in / plans / PT booking, which default to the primary branch.
  const [branchId, setBranchId] = useState<string | null>(null)
  const [tab, setTab] = useState<DashboardTab>('attendance')
  const liveCount = useLiveAttendanceCount()
  const { summary } = useDashboardSummary(branchId)
  const [from, setFrom] = useState(daysAgo(7))
  const [to, setTo] = useState(today())
  const { entries, dailyCounts, loading } = useAttendanceReport(from, to, branchId)
  const {
    page: entriesPage,
    pageCount: entriesPageCount,
    paged: pagedEntries,
    rangeStart: entriesRangeStart,
    rangeEnd: entriesRangeEnd,
    total: entriesTotal,
    setPage: setEntriesPage,
  } = usePagination(entries, PAGE_SIZE)

  // A new date range/branch can shrink the result set or just reorder it
  // entirely — always land back on page 1 rather than risk stranding the
  // Owner on a now-empty or now-mismatched page.
  useEffect(() => {
    setEntriesPage(1)
  }, [from, to, branchId, setEntriesPage])

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/dashboard">
        <div className="mx-auto flex max-w-3xl flex-col gap-4">
          {/* functional requirements §10.1/§14.5: absent for single-branch gyms; defaults to "All branches" otherwise. */}
          <div className="flex items-center justify-end">
            <BranchSwitcher branches={branches} value={branchId} onChange={setBranchId} allowAll />
          </div>

          {/* functional requirements §10.1: today's numbers, live. */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Card>
              <p className="text-sm text-ink-soft">Check-ins today</p>
              <p className="mt-1 font-display text-4xl font-bold text-ink tabular-nums">
                {liveCount === null ? '—' : liveCount}
              </p>
            </Card>
            <Card>
              <p className="text-sm text-ink-soft">Revenue today</p>
              <p className="mt-1 font-display text-4xl font-bold text-ink tabular-nums">
                {summary === null ? '—' : `$${summary.todayRevenue}`}
              </p>
            </Card>
            <Card>
              <p className="text-sm text-ink-soft">Active members</p>
              <p className="mt-1 font-display text-4xl font-bold text-ink tabular-nums">
                {summary === null ? '—' : summary.activeMembersCount}
              </p>
            </Card>
          </div>

          <Tabs items={DASHBOARD_TABS} value={tab} onChange={(value) => setTab(value as DashboardTab)} />

          {tab === 'attendance' ? (
            <Card>
              <h2 className="mb-3 text-base font-semibold text-ink">Attendance report</h2>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
              </div>

              {loading ? (
                <p className="mt-4 text-sm text-ink-soft">Loading…</p>
              ) : (
                <>
                  {/* functional requirements §10.2: "check-in counts per day... as a chart." */}
                  <div className="mt-4">
                    <AttendanceTrendChart data={dailyCounts} />
                  </div>

                  {entries.length === 0 ? (
                    <p className="mt-4 text-sm text-ink-soft">No check-ins in this range.</p>
                  ) : (
                    <>
                      <ul className="mt-4 flex flex-col gap-3">
                        {pagedEntries.map((entry) => (
                          <li key={entry.id}>
                            <Ticket className="flex items-center justify-between text-sm">
                              <span className="font-medium text-ink">{entry.memberName}</span>
                              <span className="font-mono text-xs text-ink-soft">
                                {new Date(entry.checkInAt).toLocaleString([], {
                                  month: 'short',
                                  day: 'numeric',
                                  hour: 'numeric',
                                  minute: '2-digit',
                                })}
                              </span>
                            </Ticket>
                          </li>
                        ))}
                      </ul>
                      <div className="mt-4">
                        <Pagination
                          page={entriesPage}
                          pageCount={entriesPageCount}
                          rangeStart={entriesRangeStart}
                          rangeEnd={entriesRangeEnd}
                          total={entriesTotal}
                          onChange={setEntriesPage}
                        />
                      </div>
                    </>
                  )}
                </>
              )}
            </Card>
          ) : tab === 'revenue' ? (
            <RevenueForecastCard branchId={branchId} />
          ) : tab === 'retention' ? (
            <RetentionCard branchId={branchId} />
          ) : (
            <ExportCard from={from} to={to} branchId={branchId} />
          )}
        </div>
      </NavShell>
    </div>
  )
}

/** functional requirements §10.3. */
function RevenueForecastCard({ branchId }: { branchId: string | null }) {
  const [horizon, setHorizon] = useState<30 | 60 | 90>(30)
  const { forecast, loading } = useRevenueForecast(horizon, branchId)

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between gap-3">
        <h2 className="text-base font-semibold text-ink">Revenue forecast</h2>
        <div className="w-32">
          <Select
            label="Horizon"
            value={String(horizon)}
            onChange={(e) => setHorizon(Number(e.target.value) as 30 | 60 | 90)}
            options={HORIZON_OPTIONS}
          />
        </div>
      </div>

      {loading || forecast === null ? (
        <p className="text-sm text-ink-soft">Loading…</p>
      ) : !forecast.hasEnoughData ? (
        // functional requirements §10.3: explicit "not enough data yet" state — never a misleadingly confident number.
        <p className="py-6 text-center text-sm text-ink-soft">
          Not enough data yet for a meaningful forecast — check back once this gym has more billing history.
        </p>
      ) : (
        <>
          <RevenueForecastChart historical={forecast.historical} projected={forecast.projected} />
          {forecast.method ? <p className="mt-3 text-xs text-ink-soft">{forecast.method}. Not a guaranteed figure.</p> : null}
        </>
      )}
    </Card>
  )
}

/** functional requirements §10.4: each at-risk member with their specific reason(s), never a bare score. */
function RetentionCard({ branchId }: { branchId: string | null }) {
  const { members, loaded } = useRetention(branchId)
  const { page, pageCount, paged, rangeStart, rangeEnd, total, setPage } = usePagination(members, PAGE_SIZE)

  // A branch change can shrink the result set or reorder it entirely —
  // always land back on page 1 rather than risk stranding the Owner on a
  // now-empty or now-mismatched page.
  useEffect(() => {
    setPage(1)
  }, [branchId, setPage])

  return (
    <Card>
      <h2 className="mb-3 text-base font-semibold text-ink">At-risk members</h2>
      {!loaded ? (
        <p className="text-sm text-ink-soft">Loading…</p>
      ) : members.length === 0 ? (
        <p className="py-6 text-center text-sm text-ink-soft">No one's showing risk signals right now.</p>
      ) : (
        <>
          <ul className="flex flex-col gap-3">
            {paged.map((member) => (
              <li key={member.memberId}>
                <Ticket>
                  <p className="text-sm font-medium text-ink">{member.memberName}</p>
                  <ul className="mt-1.5 list-inside list-disc text-sm text-ink-soft">
                    {member.reasons.map((reason) => (
                      <li key={reason}>{reason}</li>
                    ))}
                  </ul>
                </Ticket>
              </li>
            ))}
          </ul>
          <div className="mt-4">
            <Pagination page={page} pageCount={pageCount} rangeStart={rangeStart} rangeEnd={rangeEnd} total={total} onChange={setPage} />
          </div>
        </>
      )}
    </Card>
  )
}

/** functional requirements §10.5: pick a report + date range + format, download. */
function ExportCard({ from, to, branchId }: { from: string; to: string; branchId: string | null }) {
  const { exportReport, exporting } = useExportReport()
  const [report, setReport] = useState<ExportReportType>('attendance')
  const [format, setFormat] = useState<ExportFormat>('csv')
  const [exportFrom, setExportFrom] = useState(from)
  const [exportTo, setExportTo] = useState(to)
  const [error, setError] = useState<string | null>(null)

  async function handleExport() {
    setError(null)
    try {
      await exportReport(report, format, exportFrom, exportTo, branchId)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    }
  }

  return (
    <Card>
      <h2 className="mb-3 text-base font-semibold text-ink">Export</h2>
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select
            label="Report"
            value={report}
            onChange={(e) => setReport(e.target.value as ExportReportType)}
            options={[
              { value: 'dashboard', label: 'Dashboard summary' },
              { value: 'attendance', label: 'Attendance' },
              { value: 'revenue', label: 'Revenue' },
              { value: 'retention', label: 'At-risk members' },
            ]}
          />
          <Select
            label="Format"
            value={format}
            onChange={(e) => setFormat(e.target.value as ExportFormat)}
            options={[
              { value: 'csv', label: 'CSV' },
              { value: 'pdf', label: 'PDF' },
            ]}
          />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input label="From" type="date" value={exportFrom} onChange={(e) => setExportFrom(e.target.value)} />
          <Input label="To" type="date" value={exportTo} onChange={(e) => setExportTo(e.target.value)} />
        </div>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button onClick={handleExport} disabled={exporting} fullWidth className="sm:w-auto">
          {exporting ? 'Preparing download…' : 'Export'}
        </Button>
      </div>
    </Card>
  )
}

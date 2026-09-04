import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import type {
  AtRiskTrendDto,
  BranchComparisonDto,
  MembershipHealthDto,
  NewVsReturningDto,
  PeakHoursDto,
  RevenueGranularity,
  RevenueTrendDto,
} from './types'

/**
 * Home-dashboard chart widgets (Owner analytics slice, roadmap Phase 11).
 * One hook per `/api/v1/analytics/*` endpoint, all sharing the same tiny
 * fetch-into-state shape the rest of `src/analytics/` already uses
 * (useRetention / useRevenueForecast) — this app has no TanStack Query.
 *
 * `branchId` follows the same convention as the existing report hooks:
 * null / undefined means the gym-wide rollup. Per the confirmed design
 * split, only the branch-scoped charts (peak-hours, membership-health)
 * take a branchId that changes with the BranchSwitcher; the hub-wide ones
 * (revenue trend, branch comparison, at-risk, new-vs-returning) don't.
 */

interface AnalyticsState<T> {
  data: T | null
  loading: boolean
  /** True once a fetch has resolved (success or handled failure) at least once. */
  loaded: boolean
  error: ApiError | null
}

function useAnalyticsResource<T>(path: string): AnalyticsState<T> {
  const { authFetch } = useAuth()
  const [state, setState] = useState<AnalyticsState<T>>({ data: null, loading: true, loaded: false, error: null })

  const refresh = useCallback(async () => {
    setState((prev) => ({ ...prev, loading: true, error: null }))
    try {
      const data = await authFetch<T>(path, { method: 'GET' })
      setState({ data, loading: false, loaded: true, error: null })
    } catch (err) {
      // A branch with no data yet answers 200 with an empty series, so a
      // thrown error here is a real failure — surface it to the widget's
      // empty/error state rather than letting it crash the dashboard.
      setState({ data: null, loading: false, loaded: true, error: err instanceof ApiError ? err : new ApiError(0, 'network', 'Could not load this chart.') })
    }
  }, [authFetch, path])

  useEffect(() => {
    void refresh()
  }, [refresh])

  return state
}

function branchQuery(branchId?: string | null, extra?: Record<string, string>): string {
  const params = new URLSearchParams(extra)
  if (branchId) {
    params.set('branch_id', branchId)
  }
  const qs = params.toString()

  return qs ? `?${qs}` : ''
}

export function useRevenueTrend(granularity: RevenueGranularity, branchId?: string | null) {
  return useAnalyticsResource<RevenueTrendDto>(`/v1/analytics/revenue${branchQuery(branchId, { granularity })}`)
}

export function useMembershipHealth(branchId?: string | null) {
  return useAnalyticsResource<MembershipHealthDto>(`/v1/analytics/membership-health${branchQuery(branchId)}`)
}

export function usePeakHours(branchId?: string | null, days = 30) {
  return useAnalyticsResource<PeakHoursDto>(`/v1/analytics/peak-hours${branchQuery(branchId, { days: String(days) })}`)
}

export function useBranchComparison(period: '7d' | '30d' | '90d' = '30d') {
  return useAnalyticsResource<BranchComparisonDto>(`/v1/analytics/branch-comparison?period=${period}`)
}

export function useAtRiskTrend(weeks = 12) {
  return useAnalyticsResource<AtRiskTrendDto>(`/v1/analytics/at-risk-members?weeks=${weeks}`)
}

export function useNewVsReturning(branchId?: string | null) {
  return useAnalyticsResource<NewVsReturningDto>(`/v1/analytics/new-vs-returning${branchQuery(branchId)}`)
}

import { useCallback } from 'react'
import { useAuth } from '../auth/AuthContext'

export type BulkImportOutcome = 'created' | 'duplicate' | 'invalid'

export interface BulkImportRowResult {
  row: number
  outcome: BulkImportOutcome
  destination: string | null
  reason: string | null
}

export interface BulkImportResponse {
  results: BulkImportRowResult[]
  summary: { created: number; duplicate: number; invalid: number }
}

/** roadmap Phase 9.1 (GTM Pillar A) — the mapped, canonicalized CSV is the only thing sent to the backend. */
export function useBulkImport() {
  const { authFetch } = useAuth()

  const importCsv = useCallback(
    (csv: string) => authFetch<BulkImportResponse>('/invitations/bulk', { body: { csv } }),
    [authFetch],
  )

  return { importCsv }
}

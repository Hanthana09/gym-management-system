import { useState } from 'react'
import Papa from 'papaparse'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Select } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useBulkImport, type BulkImportResponse, type BulkImportOutcome } from '../invitations/useBulkImport'

type FieldKey = 'name' | 'email' | 'phone' | 'role' | 'ignore'

const FIELD_OPTIONS: { value: FieldKey; label: string }[] = [
  { value: 'name', label: 'Name' },
  { value: 'email', label: 'Email' },
  { value: 'phone', label: 'Phone' },
  { value: 'role', label: 'Role' },
  { value: 'ignore', label: "Don't import" },
]

const ALIASES: Record<Exclude<FieldKey, 'ignore'>, string[]> = {
  name: ['name', 'full name'],
  email: ['email', 'e-mail'],
  phone: ['phone', 'phone number', 'mobile'],
  role: ['role', 'type'],
}

function guessMapping(header: string): FieldKey {
  const normalized = header.trim().toLowerCase()
  for (const [field, aliases] of Object.entries(ALIASES) as [Exclude<FieldKey, 'ignore'>, string[]][]) {
    if (aliases.includes(normalized)) return field
  }

  return 'ignore'
}

interface ParsedCsv {
  headers: string[]
  rows: string[][]
}

const OUTCOME_STYLES: Record<BulkImportOutcome, string> = {
  created: 'bg-green-100 text-green-800',
  duplicate: 'bg-amber-100 text-amber-800',
  invalid: 'bg-red-100 text-red-800',
}

const REASON_LABELS: Record<string, string> = {
  missing_email_or_phone: 'Missing email or phone',
  invalid_role: 'Invalid role (must be coach or member)',
}

/**
 * roadmap Phase 9.1 (GTM Pillar A): upload → column-mapping preview →
 * commit → per-row report. The mapping step is what makes "inconsistent
 * columns" across real spreadsheets a non-issue — whatever the source
 * file's column order/naming, the Member/Owner explicitly confirms which
 * column means what before anything is sent to the backend, which then
 * only ever sees the canonical `name,email,phone,role` shape.
 */
export function OwnerBulkImportPage() {
  const { importCsv } = useBulkImport()
  const [parsed, setParsed] = useState<ParsedCsv | null>(null)
  const [mapping, setMapping] = useState<FieldKey[]>([])
  const [result, setResult] = useState<BulkImportResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function handleFile(file: File) {
    setError(null)
    Papa.parse<string[]>(file, {
      skipEmptyLines: true,
      complete: (results) => {
        const data = results.data
        if (data.length === 0) {
          setError('That file has no rows.')
          return
        }

        const [headers, ...rows] = data
        setParsed({ headers, rows })
        setMapping(headers.map(guessMapping))
      },
      error: () => setError('Could not read that file. Please upload a CSV.'),
    })
  }

  function updateMapping(index: number, field: FieldKey) {
    setMapping((prev) => prev.map((f, i) => (i === index ? field : f)))
  }

  async function handleImport() {
    if (!parsed) return
    setError(null)
    setSubmitting(true)

    try {
      const nameIndex = mapping.indexOf('name')
      const emailIndex = mapping.indexOf('email')
      const phoneIndex = mapping.indexOf('phone')
      const roleIndex = mapping.indexOf('role')

      const canonicalRows = parsed.rows.map((row) => ({
        name: nameIndex >= 0 ? (row[nameIndex] ?? '') : '',
        email: emailIndex >= 0 ? (row[emailIndex] ?? '') : '',
        phone: phoneIndex >= 0 ? (row[phoneIndex] ?? '') : '',
        role: roleIndex >= 0 ? (row[roleIndex] ?? '') : '',
      }))
      const csv = Papa.unparse(canonicalRows, { columns: ['name', 'email', 'phone', 'role'] })

      const response = await importCsv(csv)
      setResult(response)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  function reset() {
    setParsed(null)
    setMapping([])
    setResult(null)
    setError(null)
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/import">
        <div className="mx-auto max-w-4xl">
          <h1 className="mb-4 font-display text-lg font-semibold tracking-wide text-ink uppercase">
            Bulk import members
          </h1>

          {result ? (
            <ResultSummary result={result} onImportAnother={reset} />
          ) : parsed ? (
            <MappingPreview
              parsed={parsed}
              mapping={mapping}
              onChangeMapping={updateMapping}
              onConfirm={handleImport}
              onCancel={reset}
              submitting={submitting}
              error={error}
            />
          ) : (
            <UploadStep onFile={handleFile} error={error} />
          )}
        </div>
      </NavShell>
    </div>
  )
}

function UploadStep({ onFile, error }: { onFile: (file: File) => void; error: string | null }) {
  return (
    <Card>
      <p className="mb-3 text-sm text-ink-soft">
        Upload a CSV of people to invite — one row per person, with columns for name, email or phone, and role.
        Every imported person still has to explicitly approve before their account is active, exactly like a
        single invite.
      </p>
      <label className="flex min-h-touch w-full cursor-pointer items-center justify-center rounded-md border-2 border-dashed border-line bg-card text-sm font-medium text-ink hover:bg-paper-dim">
        Choose CSV file
        <input
          type="file"
          accept=".csv,text/csv"
          className="hidden"
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) onFile(file)
          }}
        />
      </label>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
    </Card>
  )
}

interface MappingPreviewProps {
  parsed: ParsedCsv
  mapping: FieldKey[]
  onChangeMapping: (index: number, field: FieldKey) => void
  onConfirm: () => void
  onCancel: () => void
  submitting: boolean
  error: string | null
}

/**
 * Mobile-first: one column per stacked block (label + Select + a couple
 * sample values) rather than a side-by-side table — a table wide enough
 * for 4 columns' worth of Selects doesn't fit 375px without horizontal
 * scroll, which CLAUDE.md rules out ("if a component doesn't work at
 * 375px, it's not done"). Widens to a grid at `sm:` since there's room.
 */
function MappingPreview({ parsed, mapping, onChangeMapping, onConfirm, onCancel, submitting, error }: MappingPreviewProps) {
  const hasEmailOrPhone = mapping.includes('email') || mapping.includes('phone')
  const hasRole = mapping.includes('role')

  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
        Map your columns
      </h2>
      <p className="mb-4 text-sm text-ink-soft">
        {parsed.rows.length} row{parsed.rows.length === 1 ? '' : 's'} detected. Confirm what each column means
        before importing.
      </p>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {parsed.headers.map((header, index) => {
          const samples = parsed.rows
            .slice(0, 3)
            .map((row) => row[index])
            .filter((value) => value)
          return (
            <div key={index} className="rounded-md border border-line bg-paper-dim/40 p-3">
              <p className="mb-1.5 truncate text-xs font-semibold text-ink-soft" title={header}>
                Column: "{header}"
              </p>
              <Select
                label=""
                aria-label={`Map column "${header}"`}
                value={mapping[index]}
                onChange={(event) => onChangeMapping(index, event.target.value as FieldKey)}
                options={FIELD_OPTIONS}
              />
              {samples.length > 0 ? (
                <p className="mt-1.5 truncate text-xs text-ink-soft" title={samples.join(', ')}>
                  e.g. {samples.join(', ')}
                </p>
              ) : null}
            </div>
          )
        })}
      </div>

      {!hasEmailOrPhone ? (
        <p className="mt-3 text-sm text-red-600">Map at least one column to Email or Phone.</p>
      ) : !hasRole ? (
        <p className="mt-3 text-sm text-red-600">Map a column to Role.</p>
      ) : null}
      {error ? <p className="mt-3 text-sm text-red-600">{error}</p> : null}

      <div className="mt-4 flex gap-2">
        <Button variant="secondary" fullWidth onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
        <Button fullWidth onClick={onConfirm} disabled={submitting || !hasEmailOrPhone || !hasRole}>
          {submitting ? 'Importing…' : `Import ${parsed.rows.length} row${parsed.rows.length === 1 ? '' : 's'}`}
        </Button>
      </div>
    </Card>
  )
}

function ResultSummary({ result, onImportAnother }: { result: BulkImportResponse; onImportAnother: () => void }) {
  return (
    <Card>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
        Import complete
      </h2>
      <div className="mb-4 grid grid-cols-3 gap-2 text-center">
        <div className="rounded-md bg-green-100 p-3">
          <p className="font-display text-2xl font-bold text-green-800">{result.summary.created}</p>
          <p className="text-xs font-medium text-green-800">Created</p>
        </div>
        <div className="rounded-md bg-amber-100 p-3">
          <p className="font-display text-2xl font-bold text-amber-800">{result.summary.duplicate}</p>
          <p className="text-xs font-medium text-amber-800">Duplicate</p>
        </div>
        <div className="rounded-md bg-red-100 p-3">
          <p className="font-display text-2xl font-bold text-red-800">{result.summary.invalid}</p>
          <p className="text-xs font-medium text-red-800">Invalid</p>
        </div>
      </div>

      <ul className="flex flex-col gap-2">
        {result.results.map((row) => (
          <li key={row.row} className="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2 text-sm">
            <div className="min-w-0">
              <span className="font-mono text-xs text-ink-soft">Row {row.row}</span>{' '}
              <span className="text-ink">{row.destination ?? '(no destination)'}</span>
              {row.reason ? <p className="text-xs text-ink-soft">{REASON_LABELS[row.reason] ?? row.reason}</p> : null}
            </div>
            <span className={`shrink-0 rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${OUTCOME_STYLES[row.outcome]}`}>
              {row.outcome}
            </span>
          </li>
        ))}
      </ul>

      <Button className="mt-4" fullWidth variant="secondary" onClick={onImportAnother}>
        Import another file
      </Button>
    </Card>
  )
}

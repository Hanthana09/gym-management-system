import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Pagination } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { usePagination } from '../lib/usePagination'
import { useOwnerBranches } from '../branches/useOwnerBranches'
import type { BranchDto } from '../branches/types'

const PAGE_SIZE = 20

function matchesSearch(branch: BranchDto, query: string): boolean {
  if (query === '') return true
  const haystack = [branch.name, branch.address].filter(Boolean).join(' ').toLowerCase()

  return haystack.includes(query)
}

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

/**
 * roadmap Phase 16.1: Owner's branch management — table (card list at
 * mobile, sortable-free table at lg: — same split as OwnerMembersPage,
 * per CLAUDE.md's no-horizontal-scroll rule). A row's name opens
 * BranchDetailPage, which is where edit / assign / status / delete now
 * live; this page keeps only the list, search, and create ("New branch").
 */
export function OwnerBranchesPage() {
  const { branches, loaded, createBranch } = useOwnerBranches()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)

  const visibleBranches = useMemo(() => {
    const query = search.trim().toLowerCase()

    return branches
      .filter((branch) => matchesSearch(branch, query))
      .sort((a, b) => a.name.localeCompare(b.name))
  }, [branches, search])

  const { page, pageCount, paged: pagedBranches, rangeStart, rangeEnd, total, setPage } = usePagination(
    visibleBranches,
    PAGE_SIZE,
  )

  // A new search query can shrink the result set or reorder it entirely —
  // always land back on page 1 rather than risk stranding the Owner on a
  // now-empty or now-mismatched page.
  useEffect(() => {
    setPage(1)
  }, [search, setPage])

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/branches">
        <div className="mx-auto flex max-w-4xl flex-col gap-4">
          <div className="flex items-center justify-between gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Branches</h1>
            <Button onClick={() => setCreating(true)}>New branch</Button>
          </div>

          <Input
            label="Search"
            placeholder="Search by name or address"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />

          {loaded && branches.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No branches yet.</p>
            </Card>
          ) : loaded && visibleBranches.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No branches match this search.</p>
            </Card>
          ) : null}

          {/* Card list — default (mobile/tablet) */}
          <div className="flex flex-col gap-3 lg:hidden">
            {pagedBranches.map((branch) => (
              <Card key={branch.id}>
                <button
                  type="button"
                  className="w-full text-left"
                  onClick={() => navigate(`/owner/branches/${branch.id}`)}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="truncate text-sm font-semibold text-ink underline-offset-2">
                          {branch.name}
                        </span>
                        {branch.isPrimary ? <Pill label="Primary" styles="bg-owner-soft text-owner" /> : null}
                      </div>
                      <p className="mt-0.5 text-sm text-ink-soft">{branch.address}</p>
                      {branch.phone ? <p className="text-sm text-ink-soft">{branch.phone}</p> : null}
                    </div>
                    <Pill
                      label={branch.status}
                      styles={branch.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}
                    />
                  </div>
                  <p className="mt-3 border-t border-line pt-3 text-sm text-ink-soft">
                    {branch.assignments.length === 0
                      ? 'No one assigned yet'
                      : `${branch.assignments.length} assigned`}
                  </p>
                </button>
              </Card>
            ))}
          </div>

          {/* Table — lg: and up. table-fixed + explicit column widths (CLAUDE.md: no horizontal page overflow), break-words so a long name/address wraps within its own column instead of forcing the table wider. */}
          {pagedBranches.length > 0 ? (
            <table className="hidden w-full table-fixed border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
              <thead>
                <tr className="text-left text-sm text-ink-soft">
                  <th className="w-[26%] border-b border-line px-4 py-3">Name</th>
                  <th className="w-[30%] border-b border-line px-4 py-3">Address</th>
                  <th className="w-[16%] border-b border-line px-4 py-3">Phone</th>
                  <th className="w-[14%] border-b border-line px-4 py-3">Assigned</th>
                  <th className="w-[14%] border-b border-line px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {pagedBranches.map((branch) => (
                  <tr key={branch.id} className="text-sm text-ink">
                    <td className="border-b border-line/60 px-4 py-3 font-medium break-words">
                      <button
                        type="button"
                        className="flex items-center gap-2 underline-offset-2 hover:underline"
                        onClick={() => navigate(`/owner/branches/${branch.id}`)}
                      >
                        {branch.name}
                        {branch.isPrimary ? <Pill label="Primary" styles="bg-owner-soft text-owner" /> : null}
                      </button>
                    </td>
                    <td className="border-b border-line/60 px-4 py-3 break-words text-ink-soft">{branch.address}</td>
                    <td className="border-b border-line/60 px-4 py-3 break-words text-ink-soft">{branch.phone ?? '—'}</td>
                    <td className="border-b border-line/60 px-4 py-3 text-ink-soft">
                      {branch.assignments.length === 0 ? '—' : branch.assignments.length}
                    </td>
                    <td className="border-b border-line/60 px-4 py-3">
                      <Pill
                        label={branch.status}
                        styles={branch.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}

          <Pagination page={page} pageCount={pageCount} rangeStart={rangeStart} rangeEnd={rangeEnd} total={total} onChange={setPage} />
        </div>

        <NewBranchModal open={creating} onClose={() => setCreating(false)} onCreate={createBranch} />
      </NavShell>
    </div>
  )
}

interface NewBranchModalProps {
  open: boolean
  onClose: () => void
  onCreate: (name: string, address: string, phone?: string) => Promise<BranchDto>
}

function NewBranchModal({ open, onClose, onCreate }: NewBranchModalProps) {
  const [name, setName] = useState('')
  const [address, setAddress] = useState('')
  const [phone, setPhone] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function handleClose() {
    setName('')
    setAddress('')
    setPhone('')
    setError(null)
    onClose()
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await onCreate(name, address, phone || undefined)
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open={open} onClose={handleClose} title="New branch">
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label="Address" value={address} onChange={(e) => setAddress(e.target.value)} required />
        <Input label="Phone" hint="Optional" value={phone} onChange={(e) => setPhone(e.target.value)} />
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Saving…' : 'Save'}
        </Button>
      </form>
    </Modal>
  )
}

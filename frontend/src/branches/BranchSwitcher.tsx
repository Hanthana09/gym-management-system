import type { BranchDto } from './types'

interface BranchSwitcherProps {
  branches: BranchDto[]
  /** null = "All branches" (only meaningful when allowAll is set). */
  value: string | null
  onChange: (branchId: string | null) => void
  /** Owner reports only (DESIGN-SYSTEM.md §4.2) — Coach/Staff never get an "all branches" option, they're scoped by design. */
  allowAll?: boolean
  className?: string
}

/**
 * DESIGN-SYSTEM.md §4.2: a shared primitive, not a per-screen build —
 * used anywhere branch context matters (reports filter, Owner's
 * front-desk check-in, announcement composer's branch option, Coach/
 * Staff's own branch-scoped views). This is
 * gym-management-dashboard-redesign.md Phase 2's `BranchSelector` —
 * generalized in place rather than duplicated under a new name, since
 * it was already role-agnostic and shared across every one of the call
 * sites that spec describes (Owner: all branches; Coach/Staff: pass
 * only their assigned branches; hidden if ≤1 — all already true here).
 *
 * Single-branch rule (functional requirements §14.1): renders nothing at
 * all when there's zero or one branch — not a disabled dropdown with one
 * option, genuinely absent. This check lives here, once, rather than in
 * every consumer, so no call site can forget it.
 *
 * Mobile-first (Phase 2): full-width below `md:` so it's a real,
 * thumb-friendly dropdown on phone widths, not a squeezed inline pill;
 * reverts to the original inline pill from `md:` up, unchanged from
 * before this phase.
 */
export function BranchSwitcher({ branches, value, onChange, allowAll = false, className }: BranchSwitcherProps) {
  if (branches.length <= 1) return null

  return (
    <select
      aria-label="Branch"
      value={value ?? '__all__'}
      onChange={(e) => onChange(e.target.value === '__all__' ? null : e.target.value)}
      className={
        'min-h-touch w-full rounded-full border border-line bg-card px-3 font-mono text-xs tracking-wide text-ink uppercase md:w-auto ' +
        'focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1 ' +
        (className ?? '')
      }
    >
      {allowAll ? <option value="__all__">All branches</option> : null}
      {branches.map((branch) => (
        <option key={branch.id} value={branch.id}>
          {branch.name}
        </option>
      ))}
    </select>
  )
}

/**
 * DESIGN-SYSTEM.md §4.2's default-state rule: the primary branch for
 * single-branch gyms, or the user's first assigned branch for Coach/
 * Staff. Owner reports default to the gym-wide rollup instead (handled
 * by the caller passing `null` directly — this helper is for the
 * non-report, "must pick exactly one branch" consumers: front-desk
 * check-in, PT booking, plan management).
 */
export function defaultBranchId(branches: BranchDto[]): string | null {
  const primary = branches.find((b) => b.isPrimary)

  return primary?.id ?? branches[0]?.id ?? null
}

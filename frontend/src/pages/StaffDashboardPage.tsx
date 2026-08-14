import { useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input } from '../components/ui'
import { CheckInIcon } from '../components/ui/icons'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { useMembers } from '../members/useMembers'
import type { MemberAccountStatus, MemberListItemDto } from '../members/types'

// Same palette as OwnerMembersPage's Pill — one status vocabulary
// across every screen that shows account/membership status.
const ACCOUNT_STATUS_STYLES: Record<MemberAccountStatus, string> = {
  active: 'bg-green-100 text-green-800',
  pending_approval: 'bg-amber-100 text-amber-800',
  suspended: 'bg-red-100 text-red-800',
}

const MEMBERSHIP_STATUS_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  paused: 'bg-amber-100 text-amber-800',
  expired: 'bg-gray-100 text-gray-600',
  cancelled: 'bg-red-100 text-red-800',
}

const BLOCKED_REASON_LABELS: Record<string, string> = {
  membership_expired: 'Membership expired',
  membership_paused: 'Membership paused',
  membership_cancelled: 'Membership cancelled',
  account_suspended: 'Account suspended',
  no_membership: 'No membership',
}

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

interface CheckInRowState {
  status: 'checking' | 'success' | 'blocked' | 'error'
  message: string
}

function matchesSearch(member: MemberListItemDto, query: string): boolean {
  if (query === '') return true
  const haystack = [member.name, member.email, member.phone].filter(Boolean).join(' ').toLowerCase()

  return haystack.includes(query)
}

/**
 * roadmap Phase 15.1: Staff's whole app — a scoped-down Owner view.
 * Read-only member list (no suspend/enroll actions, those stay behind
 * MemberVoter::MANAGE which has no Staff branch) plus a front-desk
 * check-in button per row, calling the new POST /members/:id/checkin.
 */
export function StaffDashboardPage() {
  const { authFetch } = useAuth()
  const { members, loaded } = useMembers()
  const [search, setSearch] = useState('')
  const [rowState, setRowState] = useState<Record<string, CheckInRowState>>({})

  const visibleMembers = useMemo(() => {
    const query = search.trim().toLowerCase()

    return members.filter((member) => member.role === 'member' && matchesSearch(member, query))
  }, [members, search])

  async function handleCheckIn(member: MemberListItemDto) {
    setRowState((prev) => ({ ...prev, [member.id]: { status: 'checking', message: '' } }))

    try {
      const result = await authFetch<{ checkInAt: string }>(`/members/${member.id}/checkin`, { method: 'POST' })
      const time = new Date(result.checkInAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
      setRowState((prev) => ({ ...prev, [member.id]: { status: 'success', message: `Checked in at ${time}` } }))
    } catch (err) {
      if (err instanceof ApiError && err.code === 'checkin_blocked' && err.reason) {
        setRowState((prev) => ({
          ...prev,
          [member.id]: { status: 'blocked', message: BLOCKED_REASON_LABELS[err.reason as string] ?? 'Check-in blocked' },
        }))
      } else {
        setRowState((prev) => ({ ...prev, [member.id]: { status: 'error', message: "Couldn't check in." } }))
      }
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="staff" title="Gym" navItems={STAFF_NAV_ITEMS} activeHref="/staff/members">
        <div className="mx-auto flex max-w-2xl flex-col gap-4">
          <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Members</h1>

          <Input
            label="Search"
            placeholder="Search by name, email, or phone"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />

          {loaded && visibleMembers.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">
                {members.length === 0 ? 'No members yet.' : 'No one matches this search.'}
              </p>
            </Card>
          ) : null}

          <div className="flex flex-col gap-3">
            {visibleMembers.map((member) => {
              const state = rowState[member.id]

              return (
                <Card key={member.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-ink">{member.name}</p>
                      <p className="truncate text-sm text-ink-soft">{member.email ?? member.phone}</p>
                    </div>
                    <Pill label={member.status} styles={ACCOUNT_STATUS_STYLES[member.status]} />
                  </div>

                  <div className="mt-3 flex items-center justify-between gap-3">
                    {member.membership ? (
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-ink-soft">{member.membership.planName}</span>
                        <Pill
                          label={member.membership.status}
                          styles={MEMBERSHIP_STATUS_STYLES[member.membership.status] ?? 'bg-gray-100 text-gray-600'}
                        />
                      </div>
                    ) : (
                      <span className="text-sm text-ink-soft">No plan</span>
                    )}

                    <Button
                      variant="secondary"
                      onClick={() => handleCheckIn(member)}
                      disabled={state?.status === 'checking'}
                    >
                      <CheckInIcon />
                      {state?.status === 'checking' ? 'Checking in…' : 'Check in'}
                    </Button>
                  </div>

                  {state && state.status !== 'checking' ? (
                    <p
                      className={`mt-2 text-sm ${
                        state.status === 'success'
                          ? 'text-green-700'
                          : state.status === 'blocked'
                            ? 'text-red-700'
                            : 'text-ink-soft'
                      }`}
                    >
                      {state.message}
                    </p>
                  ) : null}
                </Card>
              )
            })}
          </div>
        </div>
      </NavShell>
    </div>
  )
}

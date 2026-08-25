import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Pagination, Tabs, Ticket } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { useGymMemberIdSettings } from '../gym/useGymMemberIdSettings'
import { useMemberDetail } from '../members/useMemberDetail'
import { useMembers } from '../members/useMembers'
import { MemberProfileForm, type MemberProfileFormValues } from '../members/MemberProfileForm'
import { SetPasswordModal } from '../members/SetPasswordModal'
import { RecordPaymentModal } from '../billing/RecordPaymentModal'
import { useMemberBillingStatus } from '../billing/useMemberBillingStatus'
import type { AttendanceLogDto, MemberAttendancePageDto, MemberProfileFieldsInput, MemberPtScheduleDto } from '../members/types'
import type { OutstandingInvoiceDto } from '../billing/types'

type TabValue = 'profile' | 'pt-schedule' | 'attendance' | 'payments'

const TAB_ITEMS = [
  { value: 'profile', label: 'Profile' },
  { value: 'pt-schedule', label: 'PT Schedule' },
  { value: 'attendance', label: 'Attendance' },
  { value: 'payments', label: 'Payments' },
]

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

const ACCOUNT_STATUS_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  pending_approval: 'bg-amber-100 text-amber-800',
  suspended: 'bg-red-100 text-red-800',
}

// gym-management-billing-v1.md §7: ember (bg-member-soft/text-member) —
// distinct from the account-status pill above, which uses the generic
// green/amber/red status vocabulary.
const BILLING_BLOCK_REASON_LABELS: Record<string, string> = {
  subscription_inactive: 'suspended',
  absent_invoice: 'absent',
  overdue: 'overdue',
}

function BillingStatusPill({ memberId }: { memberId: string }) {
  const { status, loaded } = useMemberBillingStatus(memberId)

  if (!loaded || !status || status.subscriptionStatus === null || status.blockReason === null) return null

  return (
    <Pill
      label={BILLING_BLOCK_REASON_LABELS[status.blockReason] ?? status.blockReason}
      styles="bg-member-soft text-member"
    />
  )
}

/**
 * gym-management-member-profile-extension.md §5's Owner Member Detail
 * View — Profile / PT Schedule / Attendance / Payments tabs, Owner/Staff
 * only (this route is only linked from OwnerMembersPage's roster rows,
 * which are themselves Owner/Staff-gated). Each non-Profile tab fetches
 * lazily on first activation rather than all four calls firing at once.
 */
export function MemberDetailPage() {
  const { id } = useParams<{ id: string }>()
  const memberId = id ?? ''
  const { profile, loaded, refreshProfile, loadPtSchedule, loadAttendance } = useMemberDetail(memberId)
  const { updateProfile } = useMembers()
  const { user } = useAuth()
  const [tab, setTab] = useState<TabValue>('profile')
  const [setPasswordOpen, setSetPasswordOpen] = useState(false)

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/members">
        <div className="mx-auto flex max-w-5xl flex-col gap-4">
          {!loaded ? (
            <p className="text-sm text-ink-soft">Loading…</p>
          ) : profile === null ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">Member not found.</p>
            </Card>
          ) : (
            <>
              <div className="flex items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">{profile.name}</h1>
                  <Pill label={profile.status} styles={ACCOUNT_STATUS_STYLES[profile.status] ?? 'bg-gray-100 text-gray-600'} />
                  <BillingStatusPill memberId={memberId} />
                  {profile.memberId ? <span className="font-mono text-xs text-ink-soft">{profile.memberId}</span> : null}
                </div>
                {/* gym-management-password-auth.md §3.1: Owner-only, mirrors PasswordManagementVoter::SET_PASSWORD. */}
                {user?.role === 'owner' ? (
                  <Button
                    type="button"
                    variant="secondary"
                    className="shrink-0"
                    onClick={() => setSetPasswordOpen(true)}
                  >
                    Set password
                  </Button>
                ) : null}
              </div>

              <SetPasswordModal
                open={setPasswordOpen}
                onClose={() => setSetPasswordOpen(false)}
                userId={profile.id}
                userName={profile.name}
              />

              <Tabs items={TAB_ITEMS} value={tab} onChange={(v) => setTab(v as TabValue)} />

              {tab === 'profile' ? (
                <ProfileTab profile={profile} onSave={(fields) => updateProfile(memberId, fields)} onSaved={refreshProfile} />
              ) : null}
              {tab === 'pt-schedule' ? <PtScheduleTab load={loadPtSchedule} /> : null}
              {tab === 'attendance' ? <AttendanceTab load={loadAttendance} /> : null}
              {tab === 'payments' ? <PaymentsTab memberId={memberId} memberName={profile.name} /> : null}
            </>
          )}
        </div>
      </NavShell>
    </div>
  )
}

interface ProfileTabProps {
  profile: NonNullable<ReturnType<typeof useMemberDetail>['profile']>
  onSave: (fields: MemberProfileFieldsInput) => Promise<unknown>
  onSaved: () => Promise<unknown>
}

function ProfileTab({ profile, onSave, onSaved }: ProfileTabProps) {
  const { settings: memberIdSettings } = useGymMemberIdSettings()
  const [values, setValues] = useState<MemberProfileFormValues>({
    name: profile.name,
    email: profile.email ?? '',
    phone: profile.phone ?? '',
    memberId: profile.memberId ?? '',
    dob: profile.dob ?? '',
    gender: profile.gender ?? '',
    addressLine: profile.addressLine ?? '',
    addressCity: profile.addressCity ?? '',
    addressPostalCode: profile.addressPostalCode ?? '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  async function handleSubmit() {
    if (memberIdSettings.mode === 'manual' && values.memberId.trim() === '') {
      setError('Member ID cannot be cleared — this gym assigns Member IDs manually.')
      return
    }

    setSubmitting(true)
    setError(null)
    setSaved(false)
    try {
      await onSave({
        ...(memberIdSettings.mode === 'manual' ? { memberId: values.memberId.trim() } : {}),
        dob: values.dob || null,
        gender: values.gender || null,
        addressLine: values.addressLine || null,
        addressCity: values.addressCity || null,
        addressPostalCode: values.addressPostalCode || null,
      })
      await onSaved()
      setSaved(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <div className="mb-4 flex flex-col gap-1 text-sm text-ink-soft">
        {profile.email ? <p>{profile.email}</p> : null}
        {profile.phone ? <p>{profile.phone}</p> : null}
        {profile.membership ? (
          <p>
            {profile.membership.planName} — {profile.membership.status}
          </p>
        ) : null}
        {profile.age !== null ? <p>Age: {profile.age}</p> : null}
      </div>
      {/* Contact fields aren't editable in this phase — only dob, gender, address fields, and memberId (gym-management-member-profile-extension.md §4). */}
      <MemberProfileForm values={values} onChange={setValues} memberIdMode={memberIdSettings.mode} error={error ?? undefined} />
      <div className="mt-4 flex items-center gap-3">
        <Button onClick={handleSubmit} disabled={submitting}>
          {submitting ? 'Saving…' : 'Save changes'}
        </Button>
        {saved ? <span className="text-sm text-green-700">Saved.</span> : null}
      </div>
    </Card>
  )
}

function PtScheduleTab({ load }: { load: () => Promise<MemberPtScheduleDto> }) {
  const [data, setData] = useState<MemberPtScheduleDto | null>(null)

  useEffect(() => {
    void load().then(setData)
  }, [load])

  if (data === null) return <p className="text-sm text-ink-soft">Loading…</p>

  const isEmpty = data.ptSessions.length === 0 && data.workoutAssignments.length === 0

  if (isEmpty) {
    return (
      <Card>
        <p className="py-6 text-center text-sm text-ink-soft">No PT sessions or workout assignments yet.</p>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {data.ptSessions.map((session) => (
        <Ticket key={session.id} className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{session.coachName}</p>
            <p className="font-mono text-xs text-ink-soft">
              {new Date(session.scheduledAt).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })} · {session.durationMinutes} min
            </p>
          </div>
          <span className="font-mono text-xs text-ink-soft uppercase">{session.status}</span>
        </Ticket>
      ))}
      {data.workoutAssignments.map((assignment) => (
        <Ticket key={assignment.id} className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{assignment.scheduleName}</p>
            <p className="font-mono text-xs text-ink-soft">
              {assignment.coachName} · started {new Date(assignment.startDate).toLocaleDateString()}
            </p>
          </div>
          <span className="font-mono text-xs text-ink-soft uppercase">{assignment.status}</span>
        </Ticket>
      ))}
    </div>
  )
}

function AttendanceTab({ load }: { load: (page: number) => Promise<MemberAttendancePageDto> }) {
  const [page, setPage] = useState(1)
  const [data, setData] = useState<MemberAttendancePageDto | null>(null)

  useEffect(() => {
    void load(page).then(setData)
  }, [load, page])

  if (data === null) return <p className="text-sm text-ink-soft">Loading…</p>

  if (data.logs.length === 0) {
    return (
      <Card>
        <p className="py-6 text-center text-sm text-ink-soft">No attendance recorded yet.</p>
      </Card>
    )
  }

  const pageCount = Math.max(1, Math.ceil(data.total / data.perPage))

  return (
    <div className="flex flex-col gap-3">
      {data.logs.map((log: AttendanceLogDto) => (
        <Ticket key={log.id} className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="text-sm font-medium text-ink">{new Date(log.checkIn).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}</p>
            <p className="font-mono text-xs text-ink-soft">{log.branchName}</p>
          </div>
          <span className="font-mono text-xs text-ink-soft uppercase">{log.method}</span>
        </Ticket>
      ))}
      <Pagination
        page={page}
        pageCount={pageCount}
        rangeStart={(page - 1) * data.perPage + 1}
        rangeEnd={Math.min(page * data.perPage, data.total)}
        total={data.total}
        onChange={setPage}
      />
    </div>
  )
}

/**
 * gym-management-billing-v1.md §7: outstanding (absent/pending) invoices
 * for this member, each with a "record payment" action — replaces the old
 * "Billing not yet enabled" stub now that recurring billing exists.
 */
function PaymentsTab({ memberId, memberName }: { memberId: string; memberName: string }) {
  const { status, loaded, refresh } = useMemberBillingStatus(memberId)
  const [paying, setPaying] = useState<OutstandingInvoiceDto | null>(null)

  if (!loaded) return <p className="text-sm text-ink-soft">Loading…</p>

  if (status === null || status.subscriptionStatus === null) {
    return (
      <Card>
        <p className="py-6 text-center text-sm text-ink-soft">No recurring subscription on this membership.</p>
      </Card>
    )
  }

  if (status.outstandingInvoices.length === 0) {
    return (
      <Card>
        <p className="py-6 text-center text-sm text-ink-soft">No outstanding invoices — this member is current.</p>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {status.outstandingInvoices.map((invoice) => (
        <Ticket key={invoice.id} className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="font-mono text-sm font-medium text-ink">${invoice.amount}</p>
            <p className="text-xs text-ink-soft">
              {invoice.dueDate ? `Due ${new Date(invoice.dueDate).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })}` : null}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <span className="rounded-full bg-member-soft px-2 py-0.5 font-mono text-xs tracking-wide text-member uppercase">
              {invoice.status}
            </span>
            <Button variant="secondary" onClick={() => setPaying(invoice)}>
              Record payment
            </Button>
          </div>
        </Ticket>
      ))}

      <RecordPaymentModal
        invoice={paying ? { id: paying.id, amount: paying.amount, memberName } : null}
        onClose={() => setPaying(null)}
        onRecorded={() => void refresh()}
      />
    </div>
  )
}

import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'
import { useCoachDetail } from '../coaches/useCoachDetail'
import { useCoachManagement } from '../coaches/useCoachManagement'
import { CoachProfileForm, type CoachProfileFormValues } from '../coaches/CoachProfileForm'
import { SetPasswordModal } from '../members/SetPasswordModal'
import type { CoachProfileDetailDto } from '../coaches/types'

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

/**
 * gym-management-coach-management.md: the Owner Coach Detail screen —
 * reached from a coach row on the Members roster. Profile view + edit,
 * "Set password" (reuses the member-side SetPasswordModal; the backend
 * endpoint is role-agnostic — gym-management-password-auth.md §3.1), and
 * suspend / reactivate. Branch assignment stays on the Branches page.
 * Owner-only: this route is only linked from Owner-gated UI, and every
 * write here 403s for non-Owners at the API anyway.
 */
export function CoachDetailPage() {
  const { id } = useParams<{ id: string }>()
  const coachId = id ?? ''
  const navigate = useNavigate()
  const { user } = useAuth()
  const { coach, loaded, notFound, refresh } = useCoachDetail(coachId)
  const { updateStatus } = useCoachManagement()
  const [setPasswordOpen, setSetPasswordOpen] = useState(false)
  const [editing, setEditing] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [statusUpdating, setStatusUpdating] = useState(false)
  const [confirmingSuspend, setConfirmingSuspend] = useState(false)

  async function handleStatus(status: 'active' | 'suspended') {
    setStatusError(null)
    setStatusUpdating(true)
    try {
      await updateStatus(coachId, status)
      setConfirmingSuspend(false)
      await refresh()
    } catch (err) {
      setStatusError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setStatusUpdating(false)
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/members">
        <div className="mx-auto flex max-w-3xl flex-col gap-4">
          <button
            type="button"
            className="self-start text-sm text-ink-soft underline-offset-2 hover:underline"
            onClick={() => navigate('/owner/members')}
          >
            ← Back to members
          </button>

          {!loaded ? (
            <p className="text-sm text-ink-soft">Loading…</p>
          ) : notFound || coach === null ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">Coach not found.</p>
            </Card>
          ) : (
            <>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">{coach.name}</h1>
                  <Pill label="coach" styles="bg-coach-soft text-coach" />
                  <Pill label={coach.status} styles={ACCOUNT_STATUS_STYLES[coach.status] ?? 'bg-gray-100 text-gray-600'} />
                </div>
                {user?.role === 'owner' ? (
                  <div className="flex shrink-0 flex-wrap gap-2">
                    <Button type="button" variant="secondary" onClick={() => setSetPasswordOpen(true)}>
                      Set password
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => setEditing((v) => !v)}>
                      {editing ? 'Cancel edit' : 'Edit'}
                    </Button>
                  </div>
                ) : null}
              </div>

              <SetPasswordModal
                open={setPasswordOpen}
                onClose={() => setSetPasswordOpen(false)}
                userId={coach.id}
                userName={coach.name}
              />

              {editing ? (
                <EditCoachCard
                  coach={coach}
                  onSaved={async () => {
                    await refresh()
                    setEditing(false)
                  }}
                />
              ) : (
                <CoachSummaryCard coach={coach} />
              )}

              {user?.role === 'owner' ? (
                <Card>
                  <h2 className="mb-2 text-sm font-semibold text-ink">Account status</h2>
                  {statusError ? <p className="mb-2 text-sm text-red-600">{statusError}</p> : null}
                  {coach.status === 'suspended' ? (
                    <Button variant="secondary" onClick={() => handleStatus('active')} disabled={statusUpdating}>
                      {statusUpdating ? 'Reactivating…' : 'Reactivate coach'}
                    </Button>
                  ) : confirmingSuspend ? (
                    <div className="flex flex-col gap-2">
                      <p className="text-sm text-ink">Suspend {coach.name}? They lose access until reactivated.</p>
                      <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => setConfirmingSuspend(false)} disabled={statusUpdating}>
                          Cancel
                        </Button>
                        <Button variant="danger" onClick={() => handleStatus('suspended')} disabled={statusUpdating}>
                          {statusUpdating ? 'Suspending…' : 'Suspend'}
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <Button variant="danger" onClick={() => setConfirmingSuspend(true)}>
                      Suspend coach
                    </Button>
                  )}
                </Card>
              ) : null}
            </>
          )}
        </div>
      </NavShell>
    </div>
  )
}

function CoachSummaryCard({ coach }: { coach: CoachProfileDetailDto }) {
  return (
    <Card>
      <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <Field label="Email" value={coach.email} />
        <Field label="Phone" value={coach.phone} />
        <Field label="Specialty" value={coach.specialty} />
        <Field label="Hourly rate" value={coach.hourlyRate ? `$${coach.hourlyRate}` : null} />
        <Field
          label="Assigned branches"
          value={coach.branches.length > 0 ? coach.branches.map((b) => b.name).join(', ') : null}
        />
        <Field label="Joined" value={new Date(coach.joinedAt).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })} />
      </dl>
      {coach.bio ? (
        <div className="mt-4 border-t border-line pt-3">
          <p className="mb-1 text-sm font-medium text-ink">Bio</p>
          <p className="text-sm whitespace-pre-line text-ink-soft">{coach.bio}</p>
        </div>
      ) : null}
    </Card>
  )
}

function Field({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-ink-soft">{label}</dt>
      <dd className="mt-0.5 text-ink">{value ?? '—'}</dd>
    </div>
  )
}

function EditCoachCard({ coach, onSaved }: { coach: CoachProfileDetailDto; onSaved: () => Promise<void> }) {
  const { updateProfile } = useCoachManagement()
  const [values, setValues] = useState<CoachProfileFormValues>({
    name: coach.name,
    email: coach.email ?? '',
    phone: coach.phone ?? '',
    specialty: coach.specialty ?? '',
    bio: coach.bio ?? '',
    hourlyRate: coach.hourlyRate ?? '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit() {
    if (values.name.trim() === '') {
      setError('Name cannot be empty.')
      return
    }
    if (values.email.trim() === '' && values.phone.trim() === '') {
      setError('A coach must keep at least one of email/phone.')
      return
    }

    setSubmitting(true)
    setError(null)
    try {
      await updateProfile(coach.id, {
        name: values.name.trim(),
        email: values.email.trim() || null,
        phone: values.phone.trim() || null,
        specialty: values.specialty.trim() || null,
        bio: values.bio.trim() || null,
        hourlyRate: values.hourlyRate.trim() || null,
      })
      await onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CoachProfileForm values={values} onChange={setValues} error={error ?? undefined} />
      <div className="mt-4">
        <Button onClick={handleSubmit} disabled={submitting}>
          {submitting ? 'Saving…' : 'Save changes'}
        </Button>
      </div>
    </Card>
  )
}

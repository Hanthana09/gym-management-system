import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useReferrals } from '../referrals/useReferrals'
import type { ReferralLeadDto, ReferralLeadStatus } from '../referrals/types'

const STATUS_STYLES: Record<ReferralLeadStatus, string> = {
  new: 'bg-blue-100 text-blue-800',
  contacted: 'bg-amber-100 text-amber-800',
  converted: 'bg-green-100 text-green-800',
  declined: 'bg-gray-100 text-gray-600',
}

function StatusTag({ status }: { status: ReferralLeadStatus }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${STATUS_STYLES[status]}`}>
      {status}
    </span>
  )
}

/**
 * roadmap Phase 9.2 (GTM Pillar B — Coach-led growth). Coach-facing
 * specifically, separate from the Owner's referral screen (OwnerReferralsPage)
 * — a Coach recommending a gym they know isn't the same action as an
 * Owner's owner-to-owner referral code, so this doesn't fold into
 * Phase 3's invitation UI or reuse the Owner's screen.
 */
export function CoachReferralPage() {
  const { leads, loaded, submitLead } = useReferrals()

  return (
    <div className="h-dvh">
      <NavShell role="coach" title="Gym" navItems={COACH_NAV_ITEMS} activeHref="/coach/refer">
        <div className="mx-auto max-w-6xl">
          <h1 className="mb-4 font-display text-lg font-semibold tracking-wide text-ink uppercase">
            Recommend This Gym
          </h1>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
            <LeadForm onSubmit={submitLead} />

            <Card className="lg:col-span-2">
              <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
                Your referrals
              </h2>
              {!loaded ? (
                <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
              ) : leads.length === 0 ? (
                <p className="py-6 text-center text-sm text-ink-soft">No referrals submitted yet.</p>
              ) : (
                <ul className="flex flex-col gap-2">
                  {leads.map((lead) => (
                    <LeadRow key={lead.id} lead={lead} />
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
      </NavShell>
    </div>
  )
}

function LeadRow({ lead }: { lead: ReferralLeadDto }) {
  return (
    <li className="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{lead.prospectGymName}</p>
        <p className="truncate text-xs text-ink-soft">{lead.contactEmail ?? lead.contactPhone}</p>
      </div>
      <StatusTag status={lead.status} />
    </li>
  )
}

interface LeadFormProps {
  onSubmit: (prospectGymName: string, contactName: string, contactEmail: string, contactPhone: string) => Promise<ReferralLeadDto>
}

/** roadmap Phase 9.2 Definition of Done: "a Coach can submit a lead in under 30 seconds" — minimal fields, no unnecessary steps. */
function LeadForm({ onSubmit }: LeadFormProps) {
  const [prospectGymName, setProspectGymName] = useState('')
  const [contactName, setContactName] = useState('')
  const [contactEmail, setContactEmail] = useState('')
  const [contactPhone, setContactPhone] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSuccess(false)

    if (!prospectGymName || (!contactEmail && !contactPhone)) {
      setError('Gym name and at least one contact method are required.')
      return
    }

    setSubmitting(true)
    try {
      await onSubmit(prospectGymName, contactName, contactEmail, contactPhone)
      setSuccess(true)
      setProspectGymName('')
      setContactName('')
      setContactEmail('')
      setContactPhone('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <p className="mb-3 text-sm text-ink-soft">
        Know a gym that could use this? Send us their info and we'll follow up.
      </p>
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <Input
          label="Gym name"
          value={prospectGymName}
          onChange={(event) => setProspectGymName(event.target.value)}
          placeholder="Riverside Fitness"
          required
        />
        <Input
          label="Contact name (optional)"
          value={contactName}
          onChange={(event) => setContactName(event.target.value)}
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label="Contact email"
            type="email"
            value={contactEmail}
            onChange={(event) => setContactEmail(event.target.value)}
          />
          <Input
            label="Contact phone"
            type="tel"
            value={contactPhone}
            onChange={(event) => setContactPhone(event.target.value)}
          />
        </div>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        {success ? <p className="text-sm text-green-700">Thanks — we'll take it from here.</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Sending…' : 'Recommend this gym'}
        </Button>
      </form>
    </Card>
  )
}

import { useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useReferralCode } from '../referrals/useReferralCode'
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
 * roadmap Phase 9.2 (GTM Pillar F — Owner-to-owner referral, "refer a
 * gym, both get a free month"). Shows the Owner's own stable code +
 * usage count, and the status of gyms they've referred — a separate
 * screen from the Coach's (CoachReferralPage), not a shared component,
 * per the task's explicit instruction to keep the two flows distinct.
 */
export function OwnerReferralsPage() {
  const { code, loaded: codeLoaded } = useReferralCode()
  const { leads, loaded: leadsLoaded, submitLead } = useReferrals()
  const [copied, setCopied] = useState(false)

  async function handleCopy() {
    if (!code) return
    await navigator.clipboard.writeText(code.code)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/referrals">
        <div className="mx-auto max-w-2xl">
        <h1 className="mb-4 font-display text-lg font-semibold tracking-wide text-ink uppercase">Referrals</h1>

        <Card>
          <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
            Your referral code
          </h2>
          {!codeLoaded || !code ? (
            <p className="py-6 text-center text-sm text-ink-soft">Loading…</p>
          ) : (
            <>
              <p className="mb-3 text-sm text-ink-soft">
                Share this with another gym owner — when they sign up and mention it, you both get a free month.
              </p>
              <div className="flex items-center justify-between gap-3 rounded-md border-2 border-dashed border-line bg-paper-dim p-4">
                <span className="font-mono text-2xl font-bold tracking-wide text-ink">{code.code}</span>
                <Button variant="secondary" onClick={handleCopy}>
                  {copied ? 'Copied!' : 'Copy'}
                </Button>
              </div>
              <p className="mt-2 text-sm text-ink-soft">
                Used <span className="font-mono font-semibold text-ink">{code.usageCount}</span>{' '}
                time{code.usageCount === 1 ? '' : 's'}.
              </p>
            </>
          )}
        </Card>

        <Card className="mt-4">
          <LeadForm onSubmit={submitLead} />
        </Card>

        <Card className="mt-4">
          <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
            Gyms you've referred
          </h2>
          {!leadsLoaded ? (
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

function LeadForm({ onSubmit }: LeadFormProps) {
  const [prospectGymName, setProspectGymName] = useState('')
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
      await onSubmit(prospectGymName, '', contactEmail, contactPhone)
      setSuccess(true)
      setProspectGymName('')
      setContactEmail('')
      setContactPhone('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      <h2 className="font-display mb-3 text-base font-semibold tracking-wide text-ink uppercase">
        Know a gym owner directly?
      </h2>
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <Input
          label="Gym name"
          value={prospectGymName}
          onChange={(event) => setProspectGymName(event.target.value)}
          required
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input label="Contact email" type="email" value={contactEmail} onChange={(event) => setContactEmail(event.target.value)} />
          <Input label="Contact phone" type="tel" value={contactPhone} onChange={(event) => setContactPhone(event.target.value)} />
        </div>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        {success ? <p className="text-sm text-green-700">Thanks — we'll take it from here.</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Sending…' : 'Submit referral'}
        </Button>
      </form>
    </>
  )
}

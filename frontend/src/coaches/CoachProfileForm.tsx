import { Input } from '../components/ui'

export interface CoachProfileFormValues {
  name: string
  email: string
  phone: string
  specialty: string
  bio: string
  hourlyRate: string
}

interface CoachProfileFormProps {
  values: CoachProfileFormValues
  onChange: (values: CoachProfileFormValues) => void
  error?: string
}

/**
 * Shared by the "Add Coach" modal and the Coach Detail edit form — one
 * form, two entry points (same pattern as MemberProfileForm). Unlike the
 * member side, identity fields (name/email/phone) are editable here too
 * (gym-management-coach-management.md). DESIGN-SYSTEM.md: existing Input
 * primitive only; `bio` is the one multi-line field — a plain styled
 * <textarea>, not a new shared component for a single use.
 */
export function CoachProfileForm({ values, onChange, error }: CoachProfileFormProps) {
  function set<K extends keyof CoachProfileFormValues>(key: K, value: CoachProfileFormValues[K]) {
    onChange({ ...values, [key]: value })
  }

  return (
    <div className="flex flex-col gap-4">
      <Input label="Name" value={values.name} onChange={(e) => set('name', e.target.value)} required />
      <div className="flex flex-col gap-3 sm:flex-row">
        <Input
          label="Email"
          type="email"
          value={values.email}
          onChange={(e) => set('email', e.target.value)}
          hint="At least one of email/phone is required."
        />
        <Input label="Phone" type="tel" value={values.phone} onChange={(e) => set('phone', e.target.value)} />
      </div>

      <Input
        label="Specialty"
        value={values.specialty}
        onChange={(e) => set('specialty', e.target.value)}
        placeholder="e.g. Strength & conditioning"
      />

      <div className="w-full">
        <label htmlFor="coach-bio" className="mb-1.5 block text-sm font-medium text-ink">
          Bio
        </label>
        <textarea
          id="coach-bio"
          value={values.bio}
          onChange={(e) => set('bio', e.target.value)}
          rows={4}
          className="w-full rounded-md border border-line bg-card px-3 py-2 text-base text-ink placeholder:text-ink-soft/60 focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1"
        />
      </div>

      <Input
        label="Hourly rate"
        type="number"
        inputMode="decimal"
        min={0}
        step="0.01"
        value={values.hourlyRate}
        onChange={(e) => set('hourlyRate', e.target.value)}
        hint="Used for the personal-training revenue estimate on the finance dashboard."
      />

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
    </div>
  )
}

export const EMPTY_COACH_PROFILE_FORM_VALUES: CoachProfileFormValues = {
  name: '',
  email: '',
  phone: '',
  specialty: '',
  bio: '',
  hourlyRate: '',
}

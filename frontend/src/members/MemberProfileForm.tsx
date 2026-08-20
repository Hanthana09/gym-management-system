import { Input, Select } from '../components/ui'
import type { MemberIdMode } from '../gym/useGymMemberIdSettings'
import type { Gender } from './types'

const GENDER_OPTIONS: { value: Gender | ''; label: string }[] = [
  { value: '', label: 'Not set' },
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
  { value: 'prefer_not_to_say', label: 'Prefer not to say' },
]

export interface MemberProfileFormValues {
  name: string
  email: string
  phone: string
  memberId: string
  dob: string
  gender: Gender | ''
  addressLine: string
  addressCity: string
  addressPostalCode: string
}

interface MemberProfileFormProps {
  values: MemberProfileFormValues
  onChange: (values: MemberProfileFormValues) => void
  // Create (walk-in) also needs name/email/phone; editing an existing
  // member's profile only ever touches dob/gender/address*/memberId
  // (gym-management-member-profile-extension.md §4 — no name/email/phone
  // editing in this phase).
  includeIdentity?: boolean
  // Follow-up feature (editable/manual Member ID mode): the Member ID
  // input only renders in 'manual' mode — in 'auto' mode it's system-
  // generated and never appears in this form at all, on create or edit.
  memberIdMode: MemberIdMode
  error?: string
}

/**
 * Shared by the "Add Member" modal and the Member Detail Profile tab's
 * edit-in-place form — one form component, two entry points, per
 * gym-management-member-profile-extension.md §8. DESIGN-SYSTEM.md: no
 * new accent colors/fonts, existing Input/Select primitives only —
 * `Input type="date"` covers the DOB picker with no new component.
 */
export function MemberProfileForm({ values, onChange, includeIdentity, memberIdMode, error }: MemberProfileFormProps) {
  function set<K extends keyof MemberProfileFormValues>(key: K, value: MemberProfileFormValues[K]) {
    onChange({ ...values, [key]: value })
  }

  return (
    <div className="flex flex-col gap-4">
      {includeIdentity ? (
        <>
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
        </>
      ) : null}

      {memberIdMode === 'manual' ? (
        <Input
          label="Member ID"
          value={values.memberId}
          onChange={(e) => set('memberId', e.target.value)}
          hint="This gym assigns its own Member IDs."
          required={includeIdentity}
        />
      ) : null}

      <Input label="Date of birth" type="date" value={values.dob} onChange={(e) => set('dob', e.target.value)} />

      <Select
        label="Gender"
        value={values.gender}
        onChange={(e) => set('gender', e.target.value as Gender | '')}
        options={GENDER_OPTIONS}
      />

      <Input label="Address line" value={values.addressLine} onChange={(e) => set('addressLine', e.target.value)} />
      <div className="flex flex-col gap-3 sm:flex-row">
        <Input label="City" value={values.addressCity} onChange={(e) => set('addressCity', e.target.value)} />
        <Input
          label="Postal code"
          value={values.addressPostalCode}
          onChange={(e) => set('addressPostalCode', e.target.value)}
        />
      </div>

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
    </div>
  )
}

export const EMPTY_MEMBER_PROFILE_FORM_VALUES: MemberProfileFormValues = {
  name: '',
  email: '',
  phone: '',
  memberId: '',
  dob: '',
  gender: '',
  addressLine: '',
  addressCity: '',
  addressPostalCode: '',
}

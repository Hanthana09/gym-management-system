import { useState } from 'react'
import { Button, Modal } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useCoachManagement } from './useCoachManagement'
import {
  CoachProfileForm,
  EMPTY_COACH_PROFILE_FORM_VALUES,
  type CoachProfileFormValues,
} from './CoachProfileForm'

interface AddCoachModalProps {
  open: boolean
  onClose: () => void
  onCreated: () => void | Promise<void>
}

/**
 * gym-management-coach-management.md: direct coach creation — a new path
 * alongside the invite/approve flow (which stays available). The account
 * is active immediately, no approval step. Owner-only; this modal is
 * only ever opened from the Owner's "Add coach" button.
 */
export function AddCoachModal({ open, onClose, onCreated }: AddCoachModalProps) {
  const { create } = useCoachManagement()
  const [values, setValues] = useState<CoachProfileFormValues>(EMPTY_COACH_PROFILE_FORM_VALUES)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function handleClose() {
    setValues(EMPTY_COACH_PROFILE_FORM_VALUES)
    setError(null)
    setSubmitting(false)
    onClose()
  }

  async function handleSubmit() {
    if (values.name.trim() === '' || (values.email.trim() === '' && values.phone.trim() === '')) {
      setError('Name and at least one of email/phone are required.')
      return
    }

    setSubmitting(true)
    setError(null)
    try {
      await create({
        name: values.name.trim(),
        email: values.email.trim() || null,
        phone: values.phone.trim() || null,
        specialty: values.specialty.trim() || null,
        bio: values.bio.trim() || null,
        hourlyRate: values.hourlyRate.trim() || null,
      })
      await onCreated()
      handleClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
      setSubmitting(false)
    }
  }

  return (
    <Modal open={open} onClose={handleClose} title="Add coach">
      <div className="flex flex-col gap-4">
        <p className="rounded-md border border-line bg-paper-dim p-3 text-sm text-ink-soft">
          Creates an active coach account right away. To have the coach approve first, send an invitation instead.
        </p>
        <CoachProfileForm values={values} onChange={setValues} error={error ?? undefined} />
        <Button fullWidth onClick={handleSubmit} disabled={submitting}>
          {submitting ? 'Creating…' : 'Create coach'}
        </Button>
      </div>
    </Modal>
  )
}

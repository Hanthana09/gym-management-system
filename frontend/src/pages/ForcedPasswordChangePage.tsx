import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Card, Input } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'

/**
 * gym-management-password-auth.md §3.1 step 5: reached whenever
 * mustChangePassword is true (App.tsx's RequireAuth redirects here from
 * every other route). No `currentPassword` field — the backend doesn't
 * require one for this mandatory first change.
 */
export function ForcedPasswordChangePage() {
  const navigate = useNavigate()
  const { changePassword } = useAuth()

  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    if (newPassword.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }
    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.')
      return
    }

    setSubmitting(true)
    try {
      await changePassword({ newPassword })
      navigate('/', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-paper px-4">
      <div className="w-full max-w-sm">
        <Card>
          <div className="mb-5 text-center">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Set your password</h1>
            <p className="mt-1 text-sm text-ink-soft">
              A gym admin set a password for your account. Choose a new one to continue.
            </p>
          </div>

          <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
            <Input
              label="New password"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••"
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
              required
            />
            <Input
              label="Confirm new password"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              required
            />
            <Button type="submit" variant="hivis" fullWidth disabled={submitting}>
              {submitting ? 'Saving…' : 'Set password'}
            </Button>
          </form>

          {error ? <p className="mt-4 text-center text-sm text-red-600">{error}</p> : null}
        </Card>
      </div>
    </div>
  )
}

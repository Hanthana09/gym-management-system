import { useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Button, Card, Input } from '../components/ui'
import { apiRequest, ApiError } from '../lib/apiClient'

interface LocationState {
  identifier?: string
}

/**
 * gym-management-password-auth.md §3.2 step 4-5: identifier + code + new
 * password. Failure is always the same generic "invalid or expired code"
 * message — never distinguishes unknown identifier from a bad/expired/
 * used token.
 */
export function ResetPasswordPage() {
  const location = useLocation()
  const navigate = useNavigate()
  const state = location.state as LocationState | null

  const [identifier, setIdentifier] = useState(state?.identifier ?? '')
  const [token, setToken] = useState('')
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
      await apiRequest('/auth/reset-password', { body: { identifier, token, newPassword } })
      navigate('/login', { state: { resetSuccess: true } })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'This code is invalid or has expired.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-paper px-4">
      <div className="w-full max-w-sm">
        <Card>
          <div className="mb-5 text-center">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Reset password</h1>
            <p className="mt-1 text-sm text-ink-soft">Enter the code we sent you and choose a new password.</p>
          </div>

          <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
            <Input
              label="Email or phone"
              type="text"
              autoComplete="username"
              placeholder="you@example.com"
              value={identifier}
              onChange={(event) => setIdentifier(event.target.value)}
              required
            />
            <Input
              label="Code"
              type="text"
              autoComplete="one-time-code"
              placeholder="Paste the code you received"
              value={token}
              onChange={(event) => setToken(event.target.value)}
              required
            />
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
            <Button type="submit" fullWidth disabled={submitting}>
              {submitting ? 'Resetting…' : 'Reset password'}
            </Button>
          </form>

          {error ? <p className="mt-4 text-center text-sm text-red-600">{error}</p> : null}
        </Card>
      </div>
    </div>
  )
}

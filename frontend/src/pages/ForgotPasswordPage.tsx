import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, Card, Input } from '../components/ui'
import { apiRequest, ApiError } from '../lib/apiClient'

/**
 * gym-management-password-auth.md §3.2 step 1-2: public, always shows the
 * same generic confirmation regardless of whether the identifier matched
 * an account (no account enumeration). Hands the identifier along to
 * ResetPasswordPage via navigation state purely as a convenience prefill —
 * that screen still accepts one typed directly.
 */
export function ForgotPasswordPage() {
  const navigate = useNavigate()

  const [identifier, setIdentifier] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await apiRequest('/auth/forgot-password', { body: { identifier } })
      navigate('/reset-password', { state: { identifier } })
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
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Forgot password?</h1>
            <p className="mt-1 text-sm text-ink-soft">Enter your email or phone and we'll send you a code.</p>
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
            <Button type="submit" fullWidth disabled={submitting}>
              {submitting ? 'Sending…' : 'Send code'}
            </Button>
          </form>

          {error ? <p className="mt-4 text-center text-sm text-red-600">{error}</p> : null}

          <p className="mt-4 text-center text-sm text-ink-soft">
            <Link to="/login" className="font-medium text-ink underline">
              Back to sign in
            </Link>
          </p>
        </Card>
      </div>
    </div>
  )
}

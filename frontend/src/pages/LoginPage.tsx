import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Card, Input } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'

type LoginMode = 'password' | 'otp'

/**
 * Built entirely from shared primitives (Card, Input, Button). The
 * password/OTP toggle is two Buttons in a row — no new primitive needed
 * for a segmented control this simple (roadmap Phase 1 rule: reuse
 * before reaching for a one-off).
 */
export function LoginPage() {
  const navigate = useNavigate()
  const { login, requestOtp } = useAuth()

  const [mode, setMode] = useState<LoginMode>('password')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [destination, setDestination] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handlePasswordSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await login(email, password)
      navigate('/', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleOtpSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await requestOtp(destination)
      navigate('/login/otp', { state: { destination, expiresInSeconds: result.expiresInSeconds } })
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
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Sign in</h1>
            <p className="mt-1 text-sm text-ink-soft">Welcome back to your gym</p>
          </div>

          <div className="mb-5 flex gap-2 rounded-md bg-paper-dim p-1">
            <Button
              type="button"
              variant={mode === 'password' ? 'primary' : 'ghost'}
              fullWidth
              onClick={() => {
                setMode('password')
                setError(null)
              }}
            >
              Password
            </Button>
            <Button
              type="button"
              variant={mode === 'otp' ? 'primary' : 'ghost'}
              fullWidth
              onClick={() => {
                setMode('otp')
                setError(null)
              }}
            >
              Get a code
            </Button>
          </div>

          {mode === 'password' ? (
            <form className="flex flex-col gap-4" onSubmit={handlePasswordSubmit}>
              <Input
                label="Email"
                type="email"
                autoComplete="email"
                placeholder="you@example.com"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
              />
              <Input
                label="Password"
                type="password"
                autoComplete="current-password"
                placeholder="••••••••"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
              <Button type="submit" fullWidth disabled={submitting}>
                {submitting ? 'Signing in…' : 'Sign in'}
              </Button>
            </form>
          ) : (
            <form className="flex flex-col gap-4" onSubmit={handleOtpSubmit}>
              <Input
                label="Email or phone"
                type="text"
                autoComplete="username"
                placeholder="you@example.com"
                value={destination}
                onChange={(event) => setDestination(event.target.value)}
                required
              />
              <Button type="submit" fullWidth disabled={submitting}>
                {submitting ? 'Sending…' : 'Send code'}
              </Button>
            </form>
          )}

          {error ? <p className="mt-4 text-center text-sm text-red-600">{error}</p> : null}
        </Card>
      </div>
    </div>
  )
}

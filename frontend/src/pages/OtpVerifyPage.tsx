import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Button, Card } from '../components/ui'
import { OtpDigitInputs } from '../components/OtpDigitInputs'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'

interface LocationState {
  destination: string
  expiresInSeconds: number
}

export function OtpVerifyPage() {
  const location = useLocation()
  const navigate = useNavigate()
  const { verifyOtp, requestOtp } = useAuth()

  const state = location.state as LocationState | null

  const [code, setCode] = useState('')
  const [secondsLeft, setSecondsLeft] = useState(state?.expiresInSeconds ?? 0)
  const [error, setError] = useState<string | null>(null)
  const [verifying, setVerifying] = useState(false)
  const [resending, setResending] = useState(false)

  useEffect(() => {
    if (!state?.destination) {
      navigate('/login', { replace: true })
    }
  }, [state, navigate])

  useEffect(() => {
    if (secondsLeft <= 0) return
    const timer = setInterval(() => setSecondsLeft((s) => Math.max(0, s - 1)), 1000)

    return () => clearInterval(timer)
  }, [secondsLeft])

  if (!state?.destination) return null

  const expired = secondsLeft <= 0
  const minutes = Math.floor(secondsLeft / 60)
  const seconds = String(secondsLeft % 60).padStart(2, '0')

  async function handleVerify(fullCode: string) {
    if (fullCode.length !== 6 || verifying || expired) return
    setVerifying(true)
    setError(null)

    try {
      await verifyOtp(state!.destination, fullCode)
      navigate('/', { replace: true })
    } catch (err) {
      if (err instanceof ApiError) {
        const remaining = err.remainingAttempts
        setError(remaining !== undefined ? `${err.message} ${remaining} attempt${remaining === 1 ? '' : 's'} remaining.` : err.message)
        setCode('')
      } else {
        setError('Something went wrong. Please try again.')
      }
    } finally {
      setVerifying(false)
    }
  }

  async function handleResend() {
    setResending(true)
    setError(null)
    setCode('')

    try {
      const result = await requestOtp(state!.destination)
      setSecondsLeft(result.expiresInSeconds)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not resend the code.')
    } finally {
      setResending(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-paper px-4">
      <div className="w-full max-w-sm">
        <Card>
          <div className="mb-5 text-center">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Enter your code</h1>
            <p className="mt-1 text-sm text-ink-soft">
              We sent a 6-digit code to <span className="font-medium">{state.destination}</span>
            </p>
          </div>

          <OtpDigitInputs
            value={code}
            onChange={(next) => {
              setCode(next)
              setError(null)
              if (next.length === 6) void handleVerify(next)
            }}
            disabled={verifying || expired}
            error={Boolean(error)}
          />

          <p className="mt-3 text-center text-sm text-ink-soft">
            {expired ? 'Code expired.' : (
              <>
                Expires in <span className="font-medium tabular-nums">{minutes}:{seconds}</span>
              </>
            )}
          </p>

          {error ? <p className="mt-2 text-center text-sm text-red-600">{error}</p> : null}

          <Button
            className="mt-4"
            fullWidth
            variant="secondary"
            onClick={handleResend}
            disabled={resending}
          >
            {resending ? 'Sending…' : 'Resend code'}
          </Button>
        </Card>
      </div>
    </div>
  )
}

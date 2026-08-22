import { useState } from 'react'
import { Button, Input, Modal } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/apiClient'

type Mode = 'generate' | 'type'

interface SetPasswordModalProps {
  open: boolean
  onClose: () => void
  userId: string
  userName: string
}

/**
 * gym-management-password-auth.md §3.1: Owner-only. The returned
 * plaintext is shown exactly once — there is no way to retrieve it again
 * after this modal closes, since only the hash is ever persisted.
 */
export function SetPasswordModal({ open, onClose, userId, userName }: SetPasswordModalProps) {
  const { authFetch } = useAuth()
  const [mode, setMode] = useState<Mode>('generate')
  const [typedPassword, setTypedPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  function handleClose() {
    setMode('generate')
    setTypedPassword('')
    setError(null)
    setResult(null)
    setCopied(false)
    onClose()
  }

  async function handleSubmit() {
    if (mode === 'type' && typedPassword.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }

    setSubmitting(true)
    setError(null)
    try {
      const data = await authFetch<{ password: string }>(`/users/${userId}/set-password`, {
        method: 'POST',
        body: mode === 'type' ? { password: typedPassword } : {},
      })
      setResult(data.password)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleCopy() {
    if (!result) return
    await navigator.clipboard.writeText(result)
    setCopied(true)
  }

  return (
    <Modal open={open} onClose={handleClose} title={`Set password for ${userName}`}>
      {result ? (
        <div className="flex flex-col gap-3">
          <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This password won't be shown again. Copy it now and share it with {userName} securely.
          </p>
          <div className="flex items-center gap-2 rounded-md border border-line bg-paper-dim p-3">
            <code className="flex-1 font-mono text-sm text-ink">{result}</code>
            <Button type="button" variant="secondary" onClick={handleCopy}>
              {copied ? 'Copied' : 'Copy'}
            </Button>
          </div>
          <Button type="button" fullWidth onClick={handleClose}>
            Done
          </Button>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="flex gap-2 rounded-md bg-paper-dim p-1">
            <Button
              type="button"
              variant={mode === 'generate' ? 'primary' : 'ghost'}
              fullWidth
              onClick={() => {
                setMode('generate')
                setError(null)
              }}
            >
              Generate
            </Button>
            <Button
              type="button"
              variant={mode === 'type' ? 'primary' : 'ghost'}
              fullWidth
              onClick={() => {
                setMode('type')
                setError(null)
              }}
            >
              Type my own
            </Button>
          </div>

          {mode === 'type' ? (
            <Input
              label="Password"
              type="text"
              placeholder="At least 8 characters"
              value={typedPassword}
              onChange={(event) => setTypedPassword(event.target.value)}
            />
          ) : (
            <p className="text-sm text-ink-soft">A random password will be generated and shown here once.</p>
          )}

          {error ? <p className="text-sm text-red-600">{error}</p> : null}

          <Button type="button" variant="hivis" fullWidth disabled={submitting} onClick={handleSubmit}>
            {submitting ? 'Setting…' : 'Set password'}
          </Button>
        </div>
      )}
    </Modal>
  )
}

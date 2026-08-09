const API_URL = import.meta.env.VITE_API_URL as string

// Mercure hub is mounted on the same FrankenPHP/Caddy origin as the API
// (Phase 0) — no separate env var needed.
export const MERCURE_URL = `${API_URL}/.well-known/mercure`

export class ApiError extends Error {
  status: number
  code: string
  remainingAttempts?: number
  /** e.g. 'membership_expired' on a checkin_blocked (409) response — functional requirements §4.1. */
  reason?: string

  constructor(status: number, code: string, message: string, remainingAttempts?: number, reason?: string) {
    super(message)
    this.status = status
    this.code = code
    this.remainingAttempts = remainingAttempts
    this.reason = reason
  }
}

interface RequestOptions {
  method?: string
  body?: unknown
  accessToken?: string | null
}

/**
 * Thin fetch wrapper: JSON in/out, always sends credentials so the
 * httpOnly refresh cookie travels with every request (roadmap Phase 2 —
 * "httpOnly cookie preferred over localStorage for the refresh token").
 */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    method: options.method ?? 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(options.accessToken ? { Authorization: `Bearer ${options.accessToken}` } : {}),
    },
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(
      response.status,
      typeof data.error === 'string' ? data.error : 'unknown_error',
      typeof data.message === 'string' ? data.message : 'Something went wrong.',
      data.remainingAttempts,
      typeof data.reason === 'string' ? data.reason : undefined,
    )
  }

  return data as T
}

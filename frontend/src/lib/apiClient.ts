export const API_URL = import.meta.env.VITE_API_URL as string

// Mercure hub is mounted at the origin root, NOT under API_URL's '/api'
// prefix — Caddy serves it at exactly '/.well-known/mercure' (see
// backend/frankenphp/Caddyfile's `mercure { ... }` block and
// MERCURE_PUBLIC_URL's own unprefixed default in compose.yaml), the same
// way '/uploads' and '/media' are served straight off disk rather than
// through the API. Proxied through the dev server too — see
// vite.config.ts's dedicated '/.well-known' rule (a naive
// `${API_URL}/.well-known/mercure` here 404s: it asks the backend for
// '/api/.well-known/mercure', a path that doesn't exist).
// Every Mercure subscriber does `new URL(MERCURE_URL)` to append a topic
// param, and the URL constructor throws on a relative string with no
// base — resolving against location.origin here, once, keeps every one
// of those call sites working unchanged.
export const MERCURE_URL = new URL('/.well-known/mercure', window.location.origin).toString()

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
  /**
   * Per-call header overrides — e.g. `Accept: application/json` for the
   * one API Platform resource this app calls directly (dashboard
   * redesign's notification list), to get a flat array instead of the
   * jsonld/hydra envelope, without changing the default format every
   * other caller (including Exercise's own API Platform consumption)
   * relies on.
   */
  headers?: Record<string, string>
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
      ...options.headers,
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

/**
 * roadmap Phase 11: GET /reports/export returns a CSV/PDF file, not
 * JSON — apiRequest()'s unconditional response.json() can't handle that,
 * so this is a separate, minimal fetch that reads a Blob and pulls the
 * filename off the same Content-Disposition header the server sets.
 */
export async function apiRequestBlob(path: string, accessToken: string | null): Promise<{ blob: Blob; filename: string }> {
  const response = await fetch(`${API_URL}${path}`, {
    credentials: 'include',
    headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {},
  })

  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new ApiError(
      response.status,
      typeof data.error === 'string' ? data.error : 'unknown_error',
      typeof data.message === 'string' ? data.message : 'Something went wrong.',
    )
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = disposition.match(/filename="?([^"]+)"?/)

  return { blob: await response.blob(), filename: match ? match[1] : 'export' }
}

/**
 * roadmap Phase 15.2: PATCH /gym/branding takes an optional logo file,
 * so it needs multipart/form-data — apiRequest()'s JSON.stringify body
 * can't express that. No explicit Content-Type header here on purpose:
 * the browser sets `multipart/form-data; boundary=...` itself only when
 * left to infer it from a FormData body.
 *
 * roadmap Phase 17: expense receipts are the second multipart use case,
 * and unlike branding they're attached on *create* (POST /expenses), not
 * just update — `method` defaults to 'PATCH' so every existing call site
 * (branding) is unaffected, but a caller can pass 'POST' for creates.
 */
export async function apiRequestForm<T>(
  path: string,
  formData: FormData,
  accessToken: string | null,
  method: string = 'PATCH',
): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    method,
    credentials: 'include',
    headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {},
    body: formData,
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(
      response.status,
      typeof data.error === 'string' ? data.error : 'unknown_error',
      typeof data.message === 'string' ? data.message : 'Something went wrong.',
    )
  }

  return data as T
}

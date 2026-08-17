import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { apiRequest, apiRequestBlob, apiRequestForm, ApiError } from '../lib/apiClient'

export interface AuthUser {
  id: string
  name: string
  email: string | null
  phone: string | null
  role: 'owner' | 'coach' | 'member' | 'staff'
  status: 'pending_approval' | 'active' | 'suspended'
  whatsappOptIn: boolean
}

interface TokenResponse {
  accessToken: string
  user: AuthUser
}

interface OtpRequestResponse {
  message: string
  expiresInSeconds: number
}

type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

interface AuthContextValue {
  status: AuthStatus
  user: AuthUser | null
  login: (email: string, password: string) => Promise<void>
  requestOtp: (destination: string) => Promise<OtpRequestResponse>
  verifyOtp: (destination: string, code: string) => Promise<void>
  logout: () => void
  /** For future protected calls: attaches the access token and silently refreshes once on a 401. */
  authFetch: <T>(path: string, options?: { method?: string; body?: unknown }) => Promise<T>
  /** Same auth/refresh handling as authFetch, for endpoints that return a file (roadmap Phase 11's report export) instead of JSON. */
  authFetchBlob: (path: string) => Promise<{ blob: Blob; filename: string }>
  /** Same auth/refresh handling as authFetch, for endpoints that take a file (roadmap Phase 15.2's logo upload) instead of a JSON body. */
  authFetchForm: <T>(path: string, formData: FormData) => Promise<T>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('loading')
  const [user, setUser] = useState<AuthUser | null>(null)
  // Access token lives in memory only — never localStorage — so it can't
  // be read back out by a future XSS payload or survive a reload. Session
  // continuity across reloads comes from the httpOnly refresh cookie
  // (roadmap Phase 2), not from persisting this value.
  const accessTokenRef = useRef<string | null>(null)
  // The refresh token is single-use and rotates on every call, so two
  // concurrent /auth/refresh requests racing on the same cookie would
  // have one succeed and the other get a 401 (its token already revoked
  // by its sibling). React StrictMode's intentional double-effect-invoke
  // surfaces this immediately; the same race is also realistic with two
  // browser tabs restoring a session at once. De-duping in-flight calls
  // to a single shared promise fixes the single-tab case.
  const inFlightRefresh = useRef<Promise<boolean> | null>(null)

  const applySession = useCallback((data: TokenResponse) => {
    accessTokenRef.current = data.accessToken
    setUser(data.user)
    setStatus('authenticated')
  }, [])

  const clearSession = useCallback(() => {
    accessTokenRef.current = null
    setUser(null)
    setStatus('unauthenticated')
  }, [])

  const refresh = useCallback((): Promise<boolean> => {
    if (inFlightRefresh.current) return inFlightRefresh.current

    const promise = apiRequest<TokenResponse>('/auth/refresh')
      .then((data) => {
        applySession(data)
        return true
      })
      .catch(() => {
        clearSession()
        return false
      })
      .finally(() => {
        inFlightRefresh.current = null
      })

    inFlightRefresh.current = promise

    return promise
  }, [applySession, clearSession])

  // Silent restore on mount: the only way to resume a session after a
  // reload, since the access token itself is never persisted.
  useEffect(() => {
    void refresh()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const login = useCallback(
    async (email: string, password: string) => {
      const data = await apiRequest<TokenResponse>('/auth/login', { body: { email, password } })
      applySession(data)
    },
    [applySession],
  )

  const requestOtp = useCallback((destination: string) => {
    return apiRequest<OtpRequestResponse>('/auth/otp/request', { body: { destination } })
  }, [])

  const verifyOtp = useCallback(
    async (destination: string, code: string) => {
      const data = await apiRequest<TokenResponse>('/auth/otp/verify', { body: { destination, code } })
      applySession(data)
    },
    [applySession],
  )

  const logout = useCallback(() => {
    // Revoke the refresh token server-side and clear its cookie — without
    // this, the still-live httpOnly cookie would silently re-authenticate
    // the same user via the mount-time refresh() on the very next reload.
    // Fire-and-forget: the user is logged out locally regardless of
    // whether this network call succeeds.
    void apiRequest('/auth/logout').catch(() => {})
    clearSession()
  }, [clearSession])

  const authFetch = useCallback(
    async <T,>(path: string, options?: { method?: string; body?: unknown }): Promise<T> => {
      try {
        return await apiRequest<T>(path, { ...options, accessToken: accessTokenRef.current })
      } catch (error) {
        // Silent refresh on access-token expiry; redirect to login only
        // happens when the refresh token itself is invalid (handled by
        // clearSession() inside refresh(), which flips status to
        // 'unauthenticated' and lets route guards redirect).
        if (error instanceof ApiError && error.status === 401) {
          const refreshed = await refresh()
          if (refreshed) {
            return apiRequest<T>(path, { ...options, accessToken: accessTokenRef.current })
          }
        }
        throw error
      }
    },
    [refresh],
  )

  const authFetchBlob = useCallback(
    async (path: string): Promise<{ blob: Blob; filename: string }> => {
      try {
        return await apiRequestBlob(path, accessTokenRef.current)
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          const refreshed = await refresh()
          if (refreshed) {
            return apiRequestBlob(path, accessTokenRef.current)
          }
        }
        throw error
      }
    },
    [refresh],
  )

  const authFetchForm = useCallback(
    async <T,>(path: string, formData: FormData): Promise<T> => {
      try {
        return await apiRequestForm<T>(path, formData, accessTokenRef.current)
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          const refreshed = await refresh()
          if (refreshed) {
            return apiRequestForm<T>(path, formData, accessTokenRef.current)
          }
        }
        throw error
      }
    },
    [refresh],
  )

  return (
    <AuthContext.Provider
      value={{ status, user, login, requestOtp, verifyOtp, logout, authFetch, authFetchBlob, authFetchForm }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used within an AuthProvider')

  return context
}

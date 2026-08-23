import { useCallback, useEffect, useState } from 'react'

export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'theme'

function systemPrefersDark(): boolean {
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function readStoredTheme(): Theme {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'light' || stored === 'dark') return stored

  return systemPrefersDark() ? 'dark' : 'light'
}

function applyTheme(theme: Theme) {
  document.documentElement.classList.toggle('dark', theme === 'dark')
}

/**
 * Kept in sync with index.html's own inline script (same storage key,
 * same "stored choice wins, otherwise OS preference" logic) — that
 * script sets the class before React mounts so there's no flash of the
 * wrong theme; this hook takes over from there for the toggle button and
 * keeps every NavShell instance (each route mounts its own) reading the
 * same persisted value.
 */
export function useTheme() {
  const [theme, setTheme] = useState<Theme>(() => (typeof window === 'undefined' ? 'light' : readStoredTheme()))

  useEffect(() => {
    applyTheme(theme)
  }, [theme])

  const toggleTheme = useCallback(() => {
    setTheme((prev) => {
      const next = prev === 'dark' ? 'light' : 'dark'
      localStorage.setItem(STORAGE_KEY, next)

      return next
    })
  }, [])

  return { theme, toggleTheme }
}

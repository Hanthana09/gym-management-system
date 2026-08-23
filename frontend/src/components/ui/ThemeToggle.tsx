import { useTheme } from '../../theme/useTheme'
import { MoonIcon, SunIcon } from './icons'

/** Sits next to NotificationBell in NavShell's header — shows the icon for the mode a click would switch *to*. */
export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()

  return (
    <button
      type="button"
      aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
      onClick={toggleTheme}
      className="flex min-h-touch min-w-touch items-center justify-center rounded-md text-ink hover:bg-paper-dim focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink"
    >
      {theme === 'dark' ? <SunIcon /> : <MoonIcon />}
    </button>
  )
}

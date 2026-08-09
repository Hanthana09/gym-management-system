import type { SVGProps } from 'react'

/**
 * Minimal hand-rolled icon set (no icon-library dependency, keeps the
 * Member route bundle small per the roadmap's <200KB budget). All icons
 * are 24x24, stroke-based, and inherit color via currentColor.
 */

function IconBase(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      width={22}
      height={22}
      aria-hidden="true"
      {...props}
    />
  )
}

export function MenuIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <path d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
    </IconBase>
  )
}

export function XIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <path d="M6 6l12 12M18 6L6 18" />
    </IconBase>
  )
}

export function CheckInIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <circle cx="12" cy="12" r="9" />
      <path d="M8.25 12.5l2.5 2.5 5-5.5" />
    </IconBase>
  )
}

export function SessionsIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <rect x="3.75" y="5.25" width="16.5" height="15" rx="1.5" />
      <path d="M3.75 9.75h16.5M8 3v4M16 3v4" />
    </IconBase>
  )
}

export function TrackingIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <path d="M3.75 19.5V4.5M3.75 19.5h16.5" />
      <path d="M7 16l3.5-4 3 3L19 8" />
    </IconBase>
  )
}

export function BellIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <path d="M6 9a6 6 0 1 1 12 0c0 3.5 1 5 1.5 6h-15C5 14 6 12.5 6 9Z" />
      <path d="M10 19.5a2 2 0 0 0 4 0" />
    </IconBase>
  )
}

export function DashboardIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <rect x="3.75" y="3.75" width="7" height="7" rx="1" />
      <rect x="13.25" y="3.75" width="7" height="4.5" rx="1" />
      <rect x="13.25" y="11.25" width="7" height="9" rx="1" />
      <rect x="3.75" y="13.75" width="7" height="6.5" rx="1" />
    </IconBase>
  )
}

export function MembersIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <circle cx="9" cy="8" r="3" />
      <path d="M3.5 19a5.5 5.5 0 0 1 11 0" />
      <circle cx="17" cy="9" r="2.5" />
      <path d="M15.75 12.25c2.4 0 4.5 1.6 4.75 4.75" />
    </IconBase>
  )
}

export function PlansIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <path d="M5 3.75h14v16.5l-3.5-2.25L12 20.25l-3.5-2.25L5 20.25Z" />
      <path d="M8.5 9h7M8.5 12.5h7" />
    </IconBase>
  )
}

export function SettingsIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <IconBase {...props}>
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 13.5a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V19.5a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H4.5a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 6.1 8.7a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H10.5a1.65 1.65 0 0 0 1-1.51V4.5a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09c.29.7.83 1.19 1.51 1.4H21a2 2 0 1 1 0 4h-.09c-.68.02-1.3.4-1.51 1Z" />
    </IconBase>
  )
}

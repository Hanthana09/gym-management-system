import type { NavItem } from './NavShell'
import {
  BellIcon,
  BillingIcon,
  CheckInIcon,
  DashboardIcon,
  MembersIcon,
  PlansIcon,
  SessionsIcon,
  SettingsIcon,
  TrackingIcon,
} from './ui/icons'

// Per roadmap Phase 1: Member's four bottom-tab items.
export const MEMBER_NAV_ITEMS: NavItem[] = [
  { label: 'Check-in', href: '/member/check-in', icon: <CheckInIcon /> },
  { label: 'Sessions', href: '/member/sessions', icon: <SessionsIcon /> },
  { label: 'Tracking', href: '/member/tracking', icon: <TrackingIcon /> },
  { label: 'Notifications', href: '/member/notifications', icon: <BellIcon /> },
]

export const OWNER_NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', href: '/owner/dashboard', icon: <DashboardIcon /> },
  { label: 'Members', href: '/owner/members', icon: <MembersIcon /> },
  { label: 'Plans', href: '/owner/plans', icon: <PlansIcon /> },
  { label: 'Billing', href: '/owner/invoices', icon: <BillingIcon /> },
  { label: 'Settings', href: '/owner/settings', icon: <SettingsIcon /> },
]

export const COACH_NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', href: '/coach/dashboard', icon: <DashboardIcon /> },
  { label: 'Sessions', href: '/coach/sessions', icon: <SessionsIcon /> },
  { label: 'Members', href: '/coach/members', icon: <MembersIcon /> },
]

// roadmap Phase 15.1: Staff's whole app is this one screen — a
// scoped-down Owner view (read-only member list + check-in action),
// not a full Owner-style multi-section nav.
export const STAFF_NAV_ITEMS: NavItem[] = [
  { label: 'Members', href: '/staff/members', icon: <MembersIcon /> },
]
